<?php
/**
 * OpenTSDB
 *
 * Describe your class here
 *
 * Authors: Mark Troyer <disco@box.com>, Jeff Queisser <jeff@box.com>
 * Date Created: 6 June 2013
 *
 * @package StatusWolf.Model.Datasource
 */

class OpenTSDB extends TimeSeriesData {

  /**
   * Format for dates in OpenTSDB API queries
   *
   * @const
   */
  const OPENTSDB_DATE_FORMAT = 'Y/m/d-H:i:s';

  /**
   * Valid return types for OpenTSDB API queries
   *
   * @var array
   */
  public static $OPENTSDB_RETURN_TYPES = array('ascii', 'json');

  /**
   * The OpenTSDB host to query
   *
   * @var string
   */
  private $_host = null;

  /**
   * Key string for the OpenTSDB API query
   *
   * @var string
   */
  private $_key = null;

  /**
   * Base URL template for OpenTSDB API queries
   *
   * @var string
   */
  private $_tsdb_base_url = null;

  /**
   * API query start time as OPENTSDB_DATE_FORMAT
   *
   * @var string
   */
  private $_query_start = null;

  /**
   * API query end time as OPENTSDB_DATE_FORMAT
   * @var string
   */
  private $_query_end = null;

  /**
   * The generated OpenTSDB API query URL
   *
   * @var string
   */
  public $tsdb_query_url = null;

  /**
   * Time in seconds to trim off the query end stamp to account for
   * lag in OpenTSDB population.
   *
   * @var int
   */
  public $tsdb_query_trim = 0;

  /**
   * OpenTSDB::__construct()
   *
   * OpenTSDB time series data object constructor
   *
   * @param string $host
   * @throws SWException
   */
  public function __construct($host = null)
  {
    // Init logging for the class
    if(SWConfig::read_values('statuswolf.debug'))
    {
      $this->loggy = new KLogger(ROOT . 'app/log/', KLogger::DEBUG);
    }
    else
    {
      $this->loggy = new KLogger(ROOT . 'app/log/', KLogger::INFO);
    }
    $this->log_tag = '(' . $_SESSION['_sw_authsession']['username'] . '|' . $_SESSION['_sw_authsession']['sessionip'] . ') ';

    // Check for an OpenTSDB host provided to the constructor
    if ($host)
    {
      $this->_host = $host;
    }
    // Host not provided, find it in the datasource configuration. If there
    // is more than one possible host, choose one randomly
    else
    {
      if ($host_config = SWConfig::read_values('datasource.OpenTSDB.url'))
      {
        if (is_array($host_config))
        {
          $this->_host = $host_config[array_rand($host_config)];
        }
        else
        {
          $this->_host = $host_config;
        }
      }
      else
      {
        throw new SWException('No OpenTSDB Host configured');
      }
    }

    // Default trim is 0, check the datasource config for a value
    if ($trim = SWConfig::read_values('datasource.OpenTSDB.trim'))
    {
      $this->tsdb_query_trim = $trim;
    }

    $this->loggy->logDebug($this->log_tag . "OpenTSDB object created");
    // The template URL with the configured OpenTSDB host included
    $this->_tsdb_base_url = 'http://' . $this->_host . '/q?start=%s&end=%s%s&%s';

  }

  /**
   * OpenTSDB::_build_url()
   *
   * Function to build the OpenTSDB API query URI. Requires a string for
   * the search key. If start and end times are not provided will default
   * to a 4-hour span ending at the present minute.
   *
   * @param array $query_bits
   *    Required: $query_bits['key']
   *    Optional:
   *    $query_bits['start_time']
   *    $query_bits['end_time']
   * @return null|string
   */
  protected function _build_url(array $query_bits)
  {
    if ($query_bits['key'])
    {
      $this->_key = $query_bits['key'];
    }
    else
    {
      // If no key string is provided bail out immediately
      return null;
    }

    if (isset($query_bits['end_time']))
    {
      $this->_end_timestamp = $query_bits['end_time'];
    }
    else
    {
      $this->_end_timestamp = time() - $this->tsdb_query_trim;
    }
    $this->_end_timestamp = $this->_end_timestamp - $this->tsdb_query_trim;
    $this->_query_end = date(self::OPENTSDB_DATE_FORMAT, $this->_end_timestamp);

    if (isset($query_bits['start_time']))
    {
      $this->_start_timestamp = $query_bits['start_time'];
    }
    else
    {
      $this->_start_timestamp = $this->_end_timestamp - (HOUR * 4);
    }
    $this->_query_start = date(self::OPENTSDB_DATE_FORMAT, $this->_start_timestamp);

    $this->tsdb_query_url = sprintf($this->_tsdb_base_url, $this->_query_start, $this->_query_end, $this->_key, 'ascii');
    return $this->tsdb_query_url;
  }

  /**
   * OpenTSDB::get_raw_data()
   *
   * Function to query OpenTSDB API and store the returned data for
   * later use.
   *
   * @param array $query_bits
   *    Required:
   *    $query_bits['metric'] - metric name to search on
   *    Optional:
   *    $query_bits['tags'] - tags to filter the metrics on
   * @throws SWException
   */
  public function get_raw_data(array $query_bits)
  {
    $search_metrics = Array();
    // Make sure we were passed query building blocks
    if (empty($query_bits))
    {
      $this->loggy->logDebug($this->log_tag . "No query data found");
      throw new SWException('No query found to search on');
    }
    else
    {
      ini_set('memory_limit', '2G');
      set_time_limit(300);

      // Make sure we have a metric name to search on and build the metric
      // string with downsampler, aggregator, rate & interpolation info
      if (array_key_exists('metrics', $query_bits))
      {
        $query_bits['key'] = '';
        foreach($query_bits['metrics'] as $metric)
        {
          $qkey = '&m=';
          if (array_key_exists('agg_type', $metric))
          {
            $this->aggregation_type = $metric['agg_type'];
          }
          $qkey .= $this->aggregation_type . ':';

          if (array_key_exists('rate', $metric) && $metric['rate'])
          {
            $qkey .= 'rate:';
          }

          if (!array_key_exists('lerp', $metric) || (!$metric['lerp']))
          {
            $qkey .= 'nointerpolation:';
          }
          $qkey .= $metric['name'];

          $search_metrics[$metric['name']] = Array();

          if (array_key_exists('tags', $metric) && is_array($metric['tags']))
          {
            $search_metrics[$metric['name']] = $metric['tags'];
            $qkey .= '{';
            foreach ($metric['tags'] as $tag)
            {
              $qkey .= $tag . ',';
            }
            $qkey = rtrim($qkey, ',');
            $qkey .= '}';
          }
          $metric_keys[$qkey] = $metric['name'];
          $query_bits['key'] = $query_bits['key'] . $qkey;
        }
      }
      else
      {
        throw new SWException('No query found to search on');
      }

      if (array_key_exists('ds_interval', $metric))
      {
        $this->downsample_interval = $metric['ds_interval'];
      }
      if (array_key_exists('ds_type', $metric))
      {
        $this->downsample_type = $metric['ds_type'];
      }

    }

    $new_cache = true;

    if (array_key_exists('cache_key', $query_bits))
    {
      $cache_key = $query_bits['cache_key'];
    }
    else
    {
      $cache_key = md5($query_bits['key'] . $this->downsample_interval . $this->downsample_type . $_SESSION['_sw_authsession']['username']);
    }

    $this->loggy->logDebug(json_encode($query_bits));
    if ($query_bits['history_graph'] === "wow" && array_key_exists('previous', $query_bits) && $query_bits['previous'])
    {
      $this->loggy->logDebug($this->log_tag . 'Creating cache file for week-over-week data');
      $this->_query_cache = CACHE . 'query_cache' . DS . $cache_key . '_wow.cache';
    }
    else
    {
      $this->_query_cache = CACHE . 'query_cache' . DS . $cache_key . '.cache';
    }

    $this->loggy->logDebug($this->log_tag . 'Incoming new query setting: ' . $query_bits['new_query']);
    if (array_key_exists('new_query', $query_bits) && $query_bits['new_query'] === "true")
    {
      if (file_exists($this->_query_cache))
      {
        $this->loggy->logDebug($this->log_tag . 'Resetting query cache');
        unlink($this->_query_cache);
      }
    }

    if (file_exists($this->_query_cache))
    {
      $new_cache = false;
      $cached_query_data = file_get_contents($this->_query_cache);
      $cached_query_data = unserialize($cached_query_data);
      $span_threshold = $query_bits['end_time'] - $query_bits['time_span'];
      $cached_series_keys = array_keys($cached_query_data);
      $last_cache_entry = array_slice($cached_query_data[$cached_series_keys[0]], -1);
      $cache_end_stamp = $last_cache_entry[0]['timestamp'];
      $this->loggy->logDebug($this->log_tag . 'Checking to make sure cached data is current');
      $this->loggy->logDebug($this->log_tag . 'cache ends at ' . $cache_end_stamp . ', minimum threshold is ' . $span_threshold);
      if ($cache_end_stamp < $span_threshold)
      {
        $new_cache = true;
        unlink($this->_query_cache);
        $query_bits['start_time'] = $span_threshold;
      }
    }

    $query_url = $this->_build_url($query_bits);
    $curl = new Curl($query_url);

    $data_pull_start = time();
    try
    {
      $raw_data = $curl->request();
    }
    catch(SWException $e)
    {
      $this->loggy->logError($this->log_tag . "Failed to retrieve metrics from OpenTSDB, start time was: $this->_query_start");
      $this->loggy->logError($this->log_tag . substr($e->getMessage(), 0, 256));
      $raw_error = explode("\n", $e->getMessage());
      $error_message = array_slice($raw_error, 2);
      $error_message[1] = substr($error_message[1], 15);
      $this->ts_data = array('error', $error_message);
      return null;
    }
    $data_pull_end = time();
    $pull_time = $data_pull_end - $data_pull_start;
    $this->loggy->logInfo($this->log_tag . "Retrieved metrics from OpenTSDB, total execution time: $pull_time seconds");
    $data = explode("\n", $raw_data);

    $this->num_points = count($data);
    $graph_data = array();

    foreach ($data as $line)
    {
      $fields = explode(' ', $line);
      $metric = array_shift($fields);
      $timestamp = array_shift($fields);
      $value = array_shift($fields);
      if ($query_bits['history_graph'] === "no")
      {
        if (!empty($fields))
        {
          $tag_key = implode(' ', $fields);
        }
        else
        {
          $tag_key = '';
        }
      }
      else
      {
        if (array_key_exists('tags', $query_bits['metrics'][0]))
        {
          $tag_key = implode(' ', $query_bits['metrics'][0]['tags']);
        }
        else
        {
          $tag_key = '';
        }
      }
      $series_key = $metric . ' ' . $tag_key;
      if (($timestamp < $this->_start_timestamp) || ($timestamp > $this->_end_timestamp))
      {
        continue;
      }
      if (!isset($graph_data[$series_key]))
      {
        $graph_data[$series_key] = array();
      }
      $graph_data[$series_key][] = array('timestamp' => $timestamp, 'value' => $value);
    }

    $legend = $this->_normalize_legend(array_keys($graph_data), $search_metrics);

    foreach ($graph_data as $series => $data)
    {
      foreach ($data as $key => $row)
      {
        $timestamp[$key] = $row['timestamp'];
        $value[$key] = $row['value'];
      }
      $this->loggy->logDebug($this->log_tag . 'sorting data, ' . count($timestamp) . ' timestamps, ' . count($value) . ' values');
      array_multisort($timestamp, SORT_ASC, $value, SORT_ASC, $data);
      $this->loggy->logDebug($this->log_tag . 'Calling downsampler, interval: ' . $this->downsample_interval . ' method: ' . $this->downsample_type);
      $downsampler = new TimeSeriesDownsample($this->downsample_interval, $this->downsample_type);
      $downsampler->ts_object = @$this;
      $this->loggy->logDebug($this->log_tag . 'Downsampling data, start: ' . $this->_start_timestamp . ', end: ' . $this->_end_timestamp);
      $graph_data[$series] = $downsampler->downsample($data, $this->_start_timestamp, $this->_end_timestamp);
      if ($new_cache)
      {
        continue;
      }
      else
      {
        $this->loggy->logDebug($this->log_tag . 'Updating data for series ' . $series);
        if (array_key_exists($series, $cached_query_data))
        {
          // Check beginning of cached data to make sure it falls before the
          // beginning of the current search - accounts for time span changes
          if ($cached_query_data[$series][0]['timestamp'] > $graph_data[$series][0]['timestamp'])
          {
            unset($cached_query_data[$series]);
          }
          else
          {
            // Remove any overlap between the cached data and the new query
            $new_data_start = $graph_data[$series][0]['timestamp'];
            foreach ($cached_query_data[$series] as $i => $series_data)
            {
              if ($series_data['timestamp'] >= $new_data_start)
              {
                unset($cached_query_data[$series][$i]);
              }
            }
          }

          $this->loggy->logDebug($this->log_tag . 'Trimming ' . count($graph_data[$series]) . ' points from cached data');
          array_splice($cached_query_data[$series], 0, count($graph_data[$series]));
          $new_series_data = array_merge($cached_query_data[$series], $graph_data[$series]);
          $new_cache_data[$series] = $new_series_data;
        }
      }
    }

    if (!empty($new_cache_data))
    {
      file_put_contents($this->_query_cache, serialize($new_cache_data));
    }
    else
    {
      file_put_contents($this->_query_cache, serialize($graph_data));
    }
    $cached_keys = array_keys($graph_data);
    foreach ($cached_keys as $my_key)
    {
      if ((empty($this->start_time)) || ($graph_data[$my_key][0]['timestamp'] < $this->start_time))
      {
        $this->start_time = $graph_data[$my_key][0]['timestamp'];
      }
      $last_point = array_slice($graph_data[$my_key], -1);
      if ((empty($this->end_time)) || ($last_point[0]['timestamp'] > $this->end_time))
      {
        $this->end_time = $last_point[0]['timestamp'];
      }
    }

    $this->ts_data = $graph_data;
    $this->ts_data['cache_key'] = $cache_key;
    $this->ts_data['query_cache'] = $this->_query_cache;
    $this->ts_data['query_url'] = $this->tsdb_query_url;
    $this->ts_data['start'] = $this->start_time;
    $this->ts_data['end'] = $this->end_time;
    $this->ts_data['legend'] = $legend;

  }

  /**
   * OpenTSDB::_normalize_legend()
   *
   * Reduces the series names to make them more meaningful and readable on the resulting graphs
   *
   * @param array $graph_data
   */
  protected function _normalize_legend($raw_legends, $query_metrics)
  {
    $save_metric_names = false;
    $metrics = Array();
    $legend_pool = Array();

    $this->loggy->logDebug($this->log_tag . "Normalizing legend");
    $this->loggy->logDebug($this->log_tag . implode(', ', $raw_legends));

    $query_metrics_tags = Array();
    foreach($query_metrics as $metric => $metric_tags)
    {
      $query_metrics_tags[$metric] = Array();
      foreach ($metric_tags as $mtag)
      {
        $bits = explode('=', $mtag);
        array_push($query_metrics_tags[$metric], $bits[0]);
      }
    }

    foreach ($raw_legends as $raw_bits)
    {
      $legend_pool[$raw_bits] = explode(' ', $raw_bits);
    }

    $this->loggy->logDebug($this->log_tag . "Metrics: " . implode(', ', array_keys($query_metrics)) . "(" . count($query_metrics) . " metrics)");
    $this->loggy->logDebug($this->log_tag . "All the legend bits: " . json_encode($legend_pool));
    $this->loggy->logDebug($this->log_tag . "Query tags: " . json_encode($query_metrics_tags));

    if (count($query_metrics) > 1)
    {
      $save_metric_names = true;
    }

    foreach ($legend_pool as $series => $legend_bits)
    {
      $legend_string = '';
      if ($save_metric_names)
      {
        $legend_string = $legend_bits[0] . ' ';
      }
      $metric_name = array_shift($legend_bits);
      if (count($query_metrics_tags[$metric_name]) < 1 && !$save_metric_names)
      {
        $legend_string = $metric_name;
      }
      else
      {
        foreach ($legend_bits as $tag)
        {
          $tag_bits = explode('=', $tag);
          if (in_array($tag_bits[0], $query_metrics_tags[$metric_name]))
          {
            $legend_string = $legend_string . $tag . ' ';
          }
        }
      }
      $legends[$series] = trim($legend_string);
    }

    return $legends;

  }

  /**
   * OpenTSDB::flush_data()
   *
   * Clears the array of gathered data
   */
  public function flush_data()
  {
    unset($this->ts_data);
    $this->ts_data = array();
    $this->_start_timestamp = null;
    $this->_end_timestamp = null;
  }

}
