<?php
/**
 * Create wp rest api namespace and endpoint to post new csv files
 */
namespace Charter_Boat_Bookings;
use \DateTime;
use \DateInterval;
use \DateTimeZone;

/**
 * The assumption of this new plugin is to integrate with WP at the most basic level
 * WooCommerce can integrate, but you need an additional plugin to enable online payments for bookings
 */
class Charter_Booking {
    public $id; //charter id
    public $customer_id; //as to hold a unique ID of the customer from whatever underlying framework
    public $customer_name; //
    public $customer_email; //required
    public $customer_phone; //string
    public $start_datetime; //datetime string  in WP Timezone
    public $start_datetime_UTC; //datetime string 
    public $duration; //hours float
    public $start_date;
    public $start_time;
    public $end_datetime; //datetime string  in WP Timezone
    public $end_datetime_UTC;
    //public $end_date;
    public $end_time;
    public $buffered_time_string; //in local WP Time
    public $start_location; //location_id
    public $end_location; //location_id
    public $tickets; //number of tickets if not private
    public $is_private; //boolean
    public $booking_status;
    public $booking_meta;
    public $date_object;
    public $errors;
    public $id_query;
    public $booking_args;

    public function __construct(){
        $this->errors = array();
    }

    /**
     * Get booking by id
     */
    public function get_booking_by_id($id){
        $this->id = intval($id);
        global $wpdb;
        $booking = $wpdb->get_row(
          $wpdb->prepare("SELECT * from {$wpdb->prefix}charter_boat_bookings WHERE id=%d",
          $this->id)
        );
        $this->errors['db_error'] = $wpdb->last_error;
        $this->id_query = $wpdb->last_query;
        if($booking){
            foreach($booking as $key=>$value){
            $this->$key = $value;
            }
            //set the times
            $this->start_datetime_UTC = get_UTC_time($this->start_datetime);
            $this->set_times();
            $this->end_datetime_UTC = get_UTC_time($this->end_datetime);
            $this->set_buffered_times();
            $this->get_booking_meta();
        } 
    }
    /**
     * Get booking by start_datetime //in WP Timezone
     */
    public function get_booking_by_start_datetime($start_datetime){
        global $wpdb;
        $booking = $wpdb->get_row(
            $wpdb->prepare("SELECT * from {$wpdb->prefix}charter_boat_bookings WHERE start_datetime=%s",
            $start_datetime)
          );
          $this->errors['db_error'] = $wpdb->last_error;
          $this->id_query = $wpdb->last_query;
          if($booking){
                foreach($booking as $key=>$value){
                    $this->$key = $value;
                }
                //set the times
                $this->start_datetime_UTC = get_UTC_time($this->start_datetime);
                $this->set_times();
                $this->end_datetime_UTC = get_UTC_time($this->end_datetime);
                $this->set_buffered_times();
                $this->get_booking_meta();
          }
          
    }

    /**
     * Get booking meta
     */
    public function get_booking_meta(){
        global $wpdb;
        $booking_meta = $wpdb->get_results(
            $wpdb->prepare("SELECT * from {$wpdb->prefix}charter_boat_booking_meta WHERE booking_id=%d",
            $this->id)
          );
        $this->errors['db_error'] = $wpdb->last_error;
        $this->id_query = $wpdb->last_query;
        $this->booking_meta = (array)$booking_meta;
    }

    /**
     * pass in an array of booking details
     */
    public function save_booking($booking_args){
        //MEGTODO: set up errors for required fields
        global $wpdb;
        $this->booking_args = $booking_args;
        
        //setting up the query
        $wpdb->insert( 
            $wpdb->prefix.'charter_boat_bookings', 
            array(
                'booking_status' => $booking_args['booking_status'],
                'start_datetime' => $booking_args['start_datetime'],
                'duration' => $booking_args['duration'],
                'start_location'=>$booking_args['start_location'],
                'end_location'=>$booking_args['end_location'],
                'tickets'=>$booking_args['tickets'],
                'is_private'=>$booking_args['is_private'],
                'customer_name'=>$booking_args['customer_name'],
                'customer_phone'=>$booking_args['customer_phone'],
                'customer_email'=>$booking_args['customer_email'],
            ),
            array(
                '%s',
                '%s',
                '%f',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );
        $this->booking_id = $wpdb->insert_id;
        if($wpdb->insert_id !== 0){
           $this->get_booking_by_id($this->booking_id);
        }
        $this->errors['db_error'] = $wpdb->last_error;
    }

    /**
     * @param array args
     */
    public function edit_booking($id, $args){
       global $wpdb;
       $charter_attributes = array(
        'booking_status' => '%s',
        'start_datetime' => '%s',
        'duration'=> '%f',
        'start_location'=> '%s',
        'end_location'=> '%s',
        'tickets'=> '%d',
        'is_private'=> '%s',
        'customer_name'=> '%s',
        'customer_phone'=> '%s',
        'customer_email'=> '%s',
       );
       $type = array();
       foreach($args as $key=>$arg){
            $type[] = $charter_attributes[$key];
       }
       
        $wpdb->update(
            "{$wpdb->prefix}charter_boat_bookings",
            $args,
            array(
                'id'=>$id
            ),
            $type,
            array(
                '%d'
            )
        );
        $this->get_booking_by_id($id);
    }

    public function update_booking_meta($booking_id, $meta_key, $meta_value ){
        $wpdb->insert(
            "{$wpdb->prefix}charter_boat_booking_meta",
            array(
                'booking_id' => $booking_id,
                'meta_key'   => $meta_key,
                'meta_value' => $meta_value
            ),
            array(
                '%d',
                '%s',
                '%s',
            ),
        );
        $this->get_booking_by_id($id);
    }

    /**
     * Set booking times: end time & buffered time string
     */

    protected function set_buffered_times(){
        $boat = new Charter_Boat();
        //end buffered
        $buffered_end_datetime = new DateTime($this->end_time, new DateTimeZone(get_option('timezone_string')));
        $buffered_end_datetime->add( new DateInterval( "PT".$boat->buffer_between."M") );
        //start buffered
        $buffered_start_datetime = new DateTime($this->start_time, new DateTimeZone(get_option('timezone_string')));
        $buffered_start_datetime->sub( new DateInterval( "PT".$boat->buffer_between."M") );
        $this->buffered_time_string = $buffered_start_datetime->format('Y-m-d H:i:s').' | '.$buffered_end_datetime->format('Y-m-d H:i:s');
    }

    protected function set_times(){
        $chunks = explode(' ', $this->start_datetime);
        $this->start_date = $chunks[0];
        $this->start_time = $chunks[1];
        $this->set_end_time();
    }

    protected function set_end_time(){
        $enddate = new DateTime($this->start_time, new DateTimeZone(get_option('timezone_string')));
        $hoursminutes = $this->duration_to_hours_mins($this->duration);
        if (strpos($this->duration, '.') !== false){
            $enddate->add(new DateInterval("PT".$hoursminutes['H']."H".$hoursminutes['M']."M"));
        } else {
            $enddate->add(new DateInterval("PT".$hoursminutes['H']."H"));
        }
        
        $this->end_datetime = $this->start_date.$enddate->format(" H:i:s");
        //$this->end_date = $enddate->format('Y:m:d');
        $this->end_time = $enddate->format(" H:i:s");
    }

    /**
     * Return Hours and Minutes from Duration
     *
     * basically provides the needed information for PHP DateInterval
     *
     * @param  string $str_duration in hours float
     * @return array of integers
     */
    protected function duration_to_hours_mins($str_duration){
        $duration = (float)$str_duration;
        $duration_hours = floor($duration);
        $duration_minutes = ($duration-$duration_hours)*60;
        return array('H'=>$duration_hours, 'M'=>$duration_minutes);
    }

}


?>