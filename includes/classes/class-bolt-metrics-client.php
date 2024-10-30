<?php


namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Metric implements \JsonSerializable {
	/**
	 * @var string
	 */
	protected $key;
	/**
	 * @var array
	 */
	protected $data;

	/**
	 * @param string $key
	 * @param array $data
	 *
	 */
	function __construct( $key, $data ) {
		$this->key  = $key;
		$this->data = $data;
	}

	/**
	 * Gets JSON for object
	 *
	 * @return JSON
	 */
	public function get_metric_json() {
		return json_encode( $this );
	}

	/**
	 * Required function to use the json_encode function
	 *
	 * @return array()
	 */
	public function jsonSerialize(): mixed {
		return
			[ $this->key => $this->data ];
	}
}

/**
 * Boltpay Metric Client Helper
 */
class Bolt_Metrics_Client {

	// We shouldn't have name like '*.log'. otherwise Woocommerce shows our file on status page
	const BOLT_METRIC_FILE_MAME = 'bolt.metric';

	const METRICS_TIMESTAMP_ID = 'bolt_metrics_timestamp';
	// amount of microseconds between metrics posts
	const METRICS_POST_INTERVAL_MICROS = 30000000;

	/**
	 * The single instance of the class.
	 *
	 * @since 2.0.13
	 * @var Bolt_Metrics_Client|null
	 */
	private static $instance = null;

	/**
	 * @var string
	 */
	private $metrics_file;

	/**
	 * @var Bolt_Settings
	 */
	private $settings_instance;

	/**
	 * @var Boolean
	 */
	private $post_metrics_is_scheduled;

	/**
	 * @var integer
	 */
	private $start_time;

	public function __construct() {
		$this->metrics_file              = null;
		$this->settings_instance         = wc_bolt()->get_bolt_settings();
		$this->post_metrics_is_scheduled = false;
		$this->start_time                = null;
		wc_bolt()->get_settings();
	}

	/**
	 * Get MetricsClient Instance.
	 *
	 * @return Bolt_Metrics_Client Instance
	 * @since 2.0.11
	 * @static
	 *
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @return bool
	 *
	 * Check if merchant metrics enabled
	 */
	private function is_enabled() {
		return Bolt_Feature_Switch::instance()->is_merchant_metrics_enabled();
	}

	/**
	 * Attempts to lock a file and returns a boolean based off of the result
	 *
	 * @param Stream $working_file file that is attempting to be written to
	 *
	 * @return bool
	 */
	protected function lock_file( $working_file ) {
		return flock( $working_file, LOCK_EX | LOCK_NB );
	}

	/**
	 * Unlocks a file when finished
	 *
	 * @param Stream $working_file file that is attempting to be written to
	 *
	 * @return void
	 */
	protected function unlock_file( $working_file ) {
		flock( $working_file, LOCK_UN );    // release the lock
	}


	/**
	 * Retrieves current time for when metrics are uploaded
	 *
	 * @return int
	 */
	public function get_current_time() {
		return round( microtime( true ) * 1000 );
	}

	/**
	 * Save current time as start time
	 *
	 * @return int
	 */
	public function save_start_time() {
		$this->start_time = $this->get_current_time();
	}

	/**
	 * Return file path for metric file name
	 *
	 * @return string
	 */
	protected function get_file_path() {
		return WC_LOG_DIR . SELF::BOLT_METRIC_FILE_MAME;
	}

	/**
	 * Attempts to open a file and if it cannot open in 5 seconds it will return false
	 *
	 * @return Stream
	 */
	public function wait_for_file() {
		$count           = 0;
		$max_retry_count = 10;

		if ( $this->metrics_file == null ) {
			$this->metrics_file = $this->get_file_path();
		}

		$working_file = fopen( $this->metrics_file, "a+" );

		// logic for properly grabbing file and locking it
		while ( ! $this->lock_file( $working_file ) ) {
			if ( $count ++ < $max_retry_count ) {
				usleep( 500 );
			} else {
				return false;
				break;
			}
		}

		return $working_file;
	}

	/**
	 * Add a count metric to the array of metrics being stored
	 *
	 * @param string $key name of count metric
	 * @param int $value count hit
	 *
	 * @return Bolt_Metric
	 */
	public function format_count_metric( $key, $value ) {
		$data = array(
			BOLT_FIELD_VALUE       => $value,
			BOLT_FIELD_METRIC_TYPE => BOLT_METRIC_TYPE_COUNT,
			BOLT_FIELD_TIMESTAMP   => $this->get_current_time(),
		);

		return new Bolt_Metric( $key, $data );
	}

	/**
	 * Add a latency metric to the array of metrics being stored
	 *
	 * @param string $key name of latency metric
	 * @param int $value the total time of the metric
	 *
	 * @return void
	 */
	public function format_latency_metric( $key, $value ) {
		$data = array(
			BOLT_FIELD_VALUE       => $value,
			BOLT_FIELD_METRIC_TYPE => BOLT_METRIC_TYPE_LATENCY,
			BOLT_FIELD_TIMESTAMP   => $this->get_current_time(),
		);

		return new Bolt_Metric( $key, $data );

	}

	/**
	 * Writes a metric to the metrics file
	 *
	 * @param Bolt_Metric $metric metric and its data
	 *
	 * @return void
	 */
	public function write_metric_to_file( $metric ) {
		if ( $this->metrics_file == null ) {
			$this->metrics_file = $this->get_file_path();
		}
		$working_file = $this->wait_for_file();
		if ( $working_file ) {
			file_put_contents( $this->metrics_file, [ $metric->get_metric_json() ], FILE_APPEND );
			file_put_contents( $this->metrics_file, ",", FILE_APPEND );
			$this->unlock_file( $working_file );
			fclose( $working_file );
		}
	}

	/**
	 * Adds a count metric to the metric file
	 *
	 * @param string $count_key name of count metric
	 * @param int $count_value the count value of the metric
	 *
	 * @return void
	 */
	public function process_count_metric( $count_key, $count_value ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$metric = $this->format_count_metric( $count_key, $count_value );

		$this->write_metric_to_file( $metric );
		$this->schedule_post_metrics();
	}

	/**
	 * Adds a latency metric to the metric file
	 *
	 * @param string $latency_key name of latency metric
	 * @param int $latency_value the total time of the metric
	 *
	 * @return void
	 */
	public function process_latency_metric( $latency_key, $latency_value ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$metric = $this->format_latency_metric( $latency_key, $latency_value );

		$this->write_metric_to_file( $metric );
		$this->schedule_post_metrics();
	}

	/**
	 * Centralized logic for handling the count and latency for a metric
	 *
	 * @param string $metric_name name of count metric
	 * @param string $metric_status metric status 'success' or 'failure'
	 * @param mixed $latency_start_time the total time of the metric
	 */
	public function process_metric( $metric_name, $metric_status, $latency_start_time = null ) {
		$this->process_count_metric( $metric_name . '.' . $metric_status, 1 );
		if ( ! $latency_start_time ) {
			$latency_start_time = $this->start_time;
		}
		if ( $latency_start_time ) {
			$this->process_latency_metric( $metric_name . '.' . BOLT_METRIC_TYPE_LATENCY, $latency_start_time );
		}
	}

	public function schedule_post_metrics() {
		if ( $this->post_metrics_is_scheduled ) {
			return;
		}
		add_action( 'shutdown', array( $this, 'post_metrics' ), 0 );
		$this->post_metrics_is_scheduled = true;
	}


	/**
	 * Post Metrics Collected in File to Merchant Metrics Endpoint, returning true if successful
	 *
	 *
	 * @return boolean
	 */
	public function post_metrics() {
		// logic for properly grabbing file and locking it
		if ( ! $this->is_enabled() ) {
			return;
		}
		$previous_post_time = get_transient( self::METRICS_TIMESTAMP_ID );
		if ( $previous_post_time ) {
			$time_diff = 1000000 * ( microtime( true ) - $previous_post_time );
			if ( $time_diff < self::METRICS_POST_INTERVAL_MICROS ) {
				return;
			}
		}
		$working_file = null;
		try {
			if ( $this->metrics_file == null ) {
				$this->metrics_file = $this->get_file_path();
			}
			$working_file = $this->wait_for_file();

			if ( $working_file ) {
				// takes file contents and puts it to appropriate posting format
				$raw_file = "[" . rtrim( file_get_contents( $this->metrics_file ), "," ) . "]";
				$output   = json_decode( $raw_file, true );

				$output_metrics = [ 'metrics' => $output ];
				wc_bolt()->bolt_api_manager()->handle_api_request( 'metrics', $output_metrics );

				// If we here file is successfully posted and we can clear it
				file_put_contents( $this->metrics_file, '' );
				set_transient( self::METRICS_TIMESTAMP_ID, microtime( true ), HOUR_IN_SECONDS );

				return HTTP_STATUS_OK;
			} else {
				return;
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		} finally {
			if ( $working_file ) {
				flock( $working_file, LOCK_UN );    // release the lock
				fclose( $working_file );
			}
		}

		return;
	}
}
