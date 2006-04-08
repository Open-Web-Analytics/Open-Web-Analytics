<?php 

// setup DB tables

require_once 'wa_settings_class.php';


class wa_schema {

var $set = array();

function create_tables() {

	global $wpdb, $set;
	
	$set = array();
	$set = wa_settings::get_settings();
	
	//print_r($set);

	$schema_version - '1';

	// requests
	$sql = "DROP TABLE IF EXISTS {$set['ns']}{$set['requests_table']};
	CREATE TABLE {$set['ns']}{$set['requests_table']} (
			request_id bigint,
			inbound_visitor_id bigint, 
			invound_session_id bigint,
			visitor_id bigint, 
			session_id bigint,
			user_name  varchar(255),
			user_email  varchar(255),
			timestamp bigint,
			month varchar(255),
			day	  tinyint(2),
			dayofweek varchar(10),
			dayofyear TINYINT(3),
			weekofyear int,
			year  int(4),
			hour  tinyint(2),
			minute   tinyint(2),
			second tinyint(2),
			msec int,
			last_req bigint,
			feed_subscription_id bigint,
			referer_id varchar(255),
			document_id varchar(255),
			site varchar(255),
			site_id varchar(255),
			ip_address varchar(255),
			host varchar(255),
			host_id varchar(255),
			os varchar(255),
			os_id varchar(255),
			ua_id varchar(255),
			is_new_visitor TINYINT(1), 
			is_repeat_visitor TINYINT(1), 
			is_comment TINYINT(1),
			is_entry_page tinyint(1),
			is_robot tinyint(1),
			is_browser tinyint(1),
			is_feedreader tinyint(1),
			
			KEY request_id (request_id),
			KEY timestamp (timestamp)
		);
		
		DROP TABLE IF EXISTS {$set['ns']}{$set['sessions_table']};
		CREATE TABLE {$set['ns']}{$set['sessions_table']} (
			session_id BIGINT,
			visitor_id BIGINT,
			user_name VARCHAR(255),
			user_email  varchar(255),
			timestamp bigint,
			year INT(4),
			month varchar(255),
			day TINYINT(2),
			dayofweek varchar(10),
			dayofyear TINYINT(3),
			weekofyear int,
			hour TINYINT(2),
			minute TINYINT(2),
			last_req BIGINT,
			num_pageviews INT,
			num_comments INT,
			is_repeat_visitor TINYINT(1),
			is_new_visitor TINYINT(1),
			prior_session_lastreq BIGINT,
			prior_session_id BIGINT,
			time_sinse_priorsession INT,
			prior_session_year INT(4),
			prior_session_month varchar(255),
			prior_session_day TINYINT(2),
			prior_session_dayofweek int,
			prior_session_hour TINYINT(2),
			prior_session_minute TINYINT(2),
			os VARCHAR(255),
			os_id varchar(255),
			ua_id varchar(255),
			first_page_id BIGINT,
			last_page_id BIGINT,
			referer_id BIGINT,
			ip_address varchar(255),
			host varchar(255),
			host_id varchar(255),
			source varchar(255),
			site varchar(255),
			site_id varchar(255),
			is_robot tinyint(1),
			is_browser tinyint(1),
			is_feedreader tinyint(1),
			KEY session_id   (session_id),
			KEY timestamp      (timestamp)
		);
		
  		DROP TABLE IF EXISTS {$set['ns']}{$set['referers_table']};
		CREATE TABLE {$set['ns']}{$set['referers_table']} (
			id BIGINT,
			url varchar(255),
			site_name varchar(255),
			query_terms varchar(255),
			refering_anchortext varchar(255),
			page_title varchar(255),
			is_searchengine tinyint(1),
			PRIMARY KEY (id)
		);	
		
		DROP TABLE IF EXISTS {$set['ns']}{$set['documents_table']};
		CREATE TABLE {$set['ns']}{$set['documents_table']} (
			id BIGINT,
			url varchar(255),
			page_title varchar(255),
			page_type varchar(255),
			PRIMARY KEY (id)
		);	
		
		DROP TABLE IF EXISTS {$set['ns']}{$set['hosts_table']};
		CREATE TABLE {$set['ns']}{$set['hosts_table']} (
			id BIGINT,
			url varchar(255),
			PRIMARY KEY (id)
		);	
		
			
	  	DROP TABLE IF EXISTS {$set['ns']}{$set['os_table']};
		CREATE TABLE {$set['ns']}{$set['os_table']} (
			id BIGINT,
			name varchar(255),
			PRIMARY KEY (id)
		);		
	  	DROP TABLE IF EXISTS {$set['ns']}{$set['ua_table']};
		CREATE TABLE {$set['ns']}{$set['ua_table']} (
			id BIGINT,
			ua varchar(255),
			browser_type varchar(255),
			PRIMARY KEY (id)
		);	
		DROP TABLE IF EXISTS {$set['ns']}{$set['optinfo_table']};
		CREATE TABLE {$set['ns']}{$set['optinfo_table']} (
			request_id BIGINT,
			data_field VARCHAR(255),
			data_value VARCHAR(255),
			KEY (request_id)
		);
		";
		
		return array($sql, $schema_version);
		
		}
		
	}

?>