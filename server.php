<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/cql2solr_class.php");
// required for making remote calls
// require_once("OLS_class_lib/curl_class.php");
// required for handling xml

// required for caching
//require_once("OLS_class_lib/cache_client_class.php");

libxml_use_internal_errors(true);

class openscan_server extends webServiceServer {
  private static $xsd=null;
  public static $fields=array();

  public function __construct($inifile,$schema=null) 
  {
    //    cache::flush();
    if( $schema ) {
      if( self::$xsd==null ) {
          $dom=new DOMDocument();
          $dom->load($schema);
          self::$xsd=new DOMXPath($dom);
      }
    }
    
    parent::__construct($inifile); 
    if( empty($fields) )
      self::$fields=$this->config->get_value("fields","setup");
   
  }

  public function __destruct() {
    
  }
 
  public function openScan($params) 
  {
    //    if (!$this->aaa->has_right("openscan", 500))
    //  die("authentication_error");
    
    $namespace="http://oss.dbc.dk/ns/openscan";

    $terms=$this->terms($params);

    $this->watch->start("response_object");
    
    if( $terms )
      {
	foreach( $terms as $term ) 
	  {
	    $response_xmlobj->scanResponse->_namespace=$namespace;
	    $response_xmlobj->scanResponse->_value->term[]=$term;
	  }
      }
    if( $params->query->_value )
      {
	$response_xmlobj->scanResponse->_value->timeUsed->_namespace=$namespace;
	$response_xmlobj->scanResponse->_value->timeUsed->_value=(1000*$this->watch->splittime("timeToWait"));
	
	$response_xmlobj->scanResponse->_value->lastScanEntry->_namespace=$namespace;
	$response_xmlobj->scanResponse->_value->lastScanEntry->_value=$params->lower->_value;
      }
    
    $this->watch->stop("response_object");
   
    // if( !isset($response_xmlobj) )
    //  die( "no response_object");

    return $response_xmlobj;
  }

 
   /** \brief Echos config-settings
   *
   */
  public function show_info() 
  {
    echo "<pre>";
    echo "version             " . $this->config->get_value("version", "setup") . "<br/>";
    echo "log                 " . $this->config->get_value("logfile", "setup") . "<br/>";
    echo "</pre>";
    die();
  }

  private function terms($params) 
  {   
    // set query from params
    $query=$params->query->_value; 
    if( $query )
      $data = methods::opensearch_request($params,$this->config,$this->watch);
    else
      $data = methods::openscan_request($params,$this->config,$this->watch);
    
    return $data;    
  }
}

$server=new openscan_server("openscan.ini");
$server->handle_request();

class methods {

  public static function opensearch_request(&$params,$config,$watch)
  {
    // get opensearch settings from config
    $settings=$config->get_section("opensearch");

    if( !$timeToWait=(float)$params->timeToWait->_value )
      $timeToWait=(float)$settings['timeToWait'];

    $num_records=$settings['numRecords'];

    $watch->start("timeToWait");   
    $ret=array();


   
    // remember the original phrase
    $original_phrase=$params->lower->_value;
  
    do{
      // get scan-entries with normal scan
      $terms=self::openscan_request($params,$config,$watch,$num_records);
      if( $terms )
      foreach( $terms as $term )
	{
	  $params->lower->_value=$term->_value->name->_value;
	  if( count($ret)>=$params->limit->_value || (1000*$watch->splittime("timeToWait")) > $timeToWait)
	    {
	      $watch->stop("timeToWait");	     
	      return $ret;	
	    }
	  // check if entry is found in solr.
	  if( mini_solr::query($settings,$params->query->_value,$params->field->_value,$term->_value->name->_value,$watch) )
	    {
	      if( !in_array($term,$ret) )
		$ret[]=$term; 
	    }
	}
   
    }
    while( ($elapsed=(1000*$watch->splittime("timeToWait")) < $timeToWait));
    $watch->stop("timeToWait");
    return $ret;
  }

 /** Function for handling scan-request 
   *  @param params; The request mapped to params-object
   *  @return response;an array of terms, false if something went wrong
   */
  public static function openscan_request($params,$config=null,$watch=null,$num_records=null) 
  {
    // make an url for request    
    if( !$query=self::get_query($params,$num_records) ) {
      verbose::log(WARNING,"openScan:224::could not set query for solr");
      return false;
    }

    if( !$config ){
      verbose::log(FATAL,"No config given in openscan_request");
      return false;
    }      

    $url=$config->get_value("baseurl","setup").$query;
    if( $watch )
      $watch->start("solr");
    $xml=self::get_xml($url,$statuscode);
    if( $watch )
      $watch->stop("solr");
    
    if( $statuscode != 200 )   { 
      verbose::log(FATAL,"openscanRequest::HTTP-errorcode from solr:".$statuscode);
      return false;
    }

    if( $watch )
      $watch->start("parse");
    $ret = self::parse_response($xml,$error);    
    if( $watch )
      $watch->stop("parse");
 
    return $ret;
  }  

  /** Parse xml and map to response-array 
   *  @param xml; the xml to parse
   *  @return response; xml mapped to response-array
   */
  private static function parse_response(&$xml,&$error) 
  {
    if( !$nodelist=self::get_nodelist($xml,$error) )
      return false;
    
    $terms=array();
    foreach( $nodelist as $node ) {
      $terms[]=self::get_term($node);
    }    
    return $terms;
  }


  /**
     Parse given node and return a response_xml object
   */
  private static function get_term($node) 
  {
    $namespace="http://oss.dbc.dk/ns/openscan";

    $term->_namespace= $namespace;
    $term->_value->name->_value=$node->getAttribute("name");
    $term->_value->name->_namespace=$namespace;
    $term->_value->hitCount->_value=$node->nodeValue;
    $term->_value->hitCount->_namespace=$namespace;
    return $term;
  }
   

   /** Return a list of nodes holding result from autocomplete-request
   *  @param xml; The xml to get nodelist from
   *  @return nodelist; A list of nodes holding result; false if something went wrong
   */
  private static function get_nodelist(&$xml,&$error) 
  {
    // parse the result
    $dom = new DOMDocument('1.0', 'UTF-8');
    
    if (!$dom->LoadXML($xml) ) {
      $error="get_nodelist::Could not load XML";
      return false;
    }    
    
    $xpath=new DOMXPath($dom);
    $query="/response/lst[@name='terms']/lst/int";
    $nodelist=$xpath->query($query);
    
    return $nodelist;
  }

 
  /** Parsef params-object and map to query-parameters 
   *  @param params; params-object
   *  @return ret; given params mapped to url-parameters
   */
  private static function get_query($params,$num_records=null) 
  {
    //   print_r($params);
    //exit;
   
    
    $prefix="&terms.";
    // field and limit are the only required values 
    if( ! $params->field->_value || ! $params->limit->_value )
      return false;

    // field check
    if( openscan_server::$fields ) {
      $flag=false;
      foreach( openscan_server::$fields as $key=>$val)
        if( $val==$params->field->_value ) {
	  $flag=true;
          break;
        }      
      if( !$flag )
        die( "error in request; field not valid" );
    }
    
    $ret.= $prefix."fl=".$params->field->_value;
    //    $ret.= $prefix."rows=".$params->limit->_value;

    // Set flag for opensearch.
    $opensearch_flag=isset($params->query->_value);
    if( $opensearch_flag )
      {
	if( isset($num_records) )
	  $ret.= $prefix."limit=".$num_records;
	else
	  $ret.= $prefix."limit=20";

	$ret.= $prefix."lower.incl=false";
      }
    else
      $ret.= $prefix."limit=".$params->limit->_value;
    
    if( $lower=urlencode($params->lower->_value) )
      $ret.= $prefix."lower=".strtolower($lower);
    
    if( $params->minFrequency->_value )
      $ret.= $prefix."mincount=".$params->minFrequency->_value;
    
    if( $params->maxFrequency->_value )
      $ret.= $prefix."maxcount=".$params->maxFrequency->_value;
    
    if( $params->prefix->_value )
      $ret.= $prefix."prefix=".$params->prefix->_value;
    
    if( $params->upper->_value )
      $ret.= $prefix."upper=".$params->upper->_value;
    
    //always sort by index
    $ret.=$prefix."sort=index";  

     
    return $ret;          
  }

   /** Get xml from solr/autocomplete interface. Set statuscode for remote-call 
   *  @param url; url and query-parameters for solr-interface
   *  @param statuscode; The statuscode to be set.
   *  @return xml; The response from solr/autocomplete
   */
  private static function get_xml($url,&$statuscode) 
  {
  
    // use curl class to retrieve results
    $curl=new curl();
    $curl->set_url($url);

    //  echo $url;
    //exit;

    $xml=$curl->get();
    
    $statuscode=$curl->get_status('http_code');
    
    return $xml;
  }  
}


class mini_solr
{
  private static $reserved = array("!"=>"","eller"=>"",":"=>"","?"=>"","-"=>"","["=>"","]"=>"");

  public static function ws_query(&$settings,$cql,$field,$phrase=null,$watch)
  {
    if( $more=self::set_cql($field,$phrase) )
    $cql.=" AND ".$more;

    $url=$settings['opensearch_url']."query=".urlencode($cql);

    
    $curl=new curl();
    $curl->set_url($url);
    $watch->start("ws_opensearch");
    $result=$curl->get();
    $watch->stop("ws_opensearch");

    // errorcheck
    $status=$curl->get_status();
    
    verbose::log(WARNING,$status['http_code'].$url);

    if( $status['http_code'] > 200 )
     {
       die( $status['http_code'].$url );
       // TODO log
       return false;
     }
   
    
    $ret=self::ws_check($result);
    /*   if( $ret )
      {
	echo $url;
	exit;
	}*/

    return $ret;
    
  }

  public static function query(&$settings,$cql,$field,$phrase=null,$watch)
  {
    foreach(self::$reserved as $key=>$val)
      {
	$search[]=$key;
	$replace[]=$val;
      }

    $phrase=str_replace($search,"",$phrase);

    if( $more=self::set_cql($field,$phrase) )
      $cql.=" AND ".$more;

    $query=self::convert($cql);

    $url=$settings['solr_uri'].$settings['solr_params']."q=".$query['solr'];

    $curl=new curl();
    $curl->set_url($url);
    $watch->start("mini_solr");
    $result=$curl->get();
    $watch->stop("mini_solr");
    // errorcheck
    $status=$curl->get_status();

    if( $status['http_code'] > 200 )
     {
       // TODO log
       return false;
     }
  
    $ret=self::check($result);
    /* if( $ret )
      {
	echo $url;
	exit;
	}*/
    return $ret;
  }


  private static function convert($cql)
  {
    $solr=new cql2solr('opensearch_cql.xml');

    $query=$solr->convert($cql);

    return $query;
  }

 
  private static function set_cql($field,$phrase=null)
  {
    if( strlen($field)>1  && strlen($phrase)>1 )
      {
	$fields=explode(".",$field);

	if( $fields[1]=="anyIndexes" )
	  $ret="cql.anyIndexes";
	else
	  $ret="dc.".$fields[1];
	
	$ret.="=".$phrase;
	return $ret;
      }
  }


  private static function ws_check(&$xml)
  {
    $dom = new DOMDocument('1.0', 'UTF-8');
    
    if (!$dom->LoadXML($xml) ) {
      //   verbose::log(WARNING,print_r(libxml_get_errors()) );
      echo $xml;
      exit;
      libxml_clear_errors();
      return false;
    }    
    
    $xpath=new DOMXPath($dom);

    $xpath->registerNameSpace("wtf","http://oss.dbc.dk/ns/opensearch");
    $query="//wtf:hitCount";
    $nodelist=$xpath->query($query);
    
    return( $nodelist->item(0)->nodeValue > 0 );

  }

  private static function check(&$xml)
  {
    $dom = new DOMDocument('1.0', 'UTF-8');
    
    if (!$dom->LoadXML($xml) ) {
      // die( "No DOM" );
      //   verbose::log(WARNING,print_r(libxml_get_errors()) );

      echo $xml;
      exit;
      libxml_clear_errors();
      return false;
    }    
    
    $xpath=new DOMXPath($dom);
    $query="//result";
    $nodelist=$xpath->query($query);

    //  echo  $nodelist->item(0)->getAttribute('numFound')."<br />";
    return( $nodelist->item(0)->getAttribute('numFound') > 0 );
   
  }  
}
?>


