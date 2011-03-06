<?php
// Last update: v.01 Mar 5, 2011
// Export ExpressionEngine Comments to Disqus using PHP, EE1/EE2 and pre-3.0 Disqus API
// Copyright 2011 Jason Hamilton-Mascioli KickStartLabs:0 (http://www.kickstartlabs.com), released to public domain

/** INSTALLATION AND SETUP
------------------

1. place ee_disqus_exporter.php file in server root
2. modify configuration
3. enable debugging near bottom of script
4. run script from url and wait (view disqus to see comments being added) ... you may need to approve them in disqus

*/

date_default_timezone_set('America/New_York');

// CONFIGURATION ***********************************************************************

// API URL
$disqus_url = 'http://disqus.com/api';

// CONFIG
$user_api_key = ''; // GET FROM DISQUS

// PROD
$forum_api_key = ''; // GET FROM DISQUS
$forum_shortname = ''; // GET FROM DISQUS
$current_blog_base_url = ''; // http://mysite.com
$db_user = '';
$db_password = '';
$db_host = '';
$db_name = '';
$ee_version = 2; // Expression Engine Version 1 or 2

// CONNECT TO THE DB
$con = mysql_connect($db_host,$db_user,$db_password);
$db_ok = mysql_select_db($db_name,$con);

// DETERMINE WHICH VERSION OF EE WE ARE USING
if($ee_version == 2)
  $titles_table = "exp_channel_titles";
else
  $titles_table = "exp_weblog_titles";

// CONFIGURATION END********************************************************************

// CONVERT SPECIAL CHARS
function ConvertCharacters($String, $ConvertTo='entity') {
  // Build character map list
    $exclude = array(129, 141, 143, 144, 157);
    for($i=128; $i<=255; $i++)
      $characterMap['&#'.$i.';'] = chr($i);
    foreach($exclude as $i)
      unset($characterMap['&#'.$i.';']);
  
  // Assign find and replace variables
  switch($ConvertTo)
  {
    case 'ascii': // To ascii characters
      $find = array_keys($characterMap);
      $replace = array_values($characterMap);
      break;
    case 'entity': // To numbered entities
    default:
      $find = array_values($characterMap);
      $replace = array_keys($characterMap);
      break;
  }
  
  // Convert characters within string and return results
  return str_replace($find, $replace, $String);
} 

// CURL TO DISQUS
function resourceCurl($Url,$type = "GET",array $fields = array()){
 
    // is curl installed?
    if (!function_exists('curl_init')){ 
        die('CURL is not installed!');
    }
 
    // create a new curl resource
    $ch = curl_init();
   
    // set URL to download
    curl_setopt($ch, CURLOPT_URL, $Url);
 
    if($type == "POST"){
      curl_setopt($ch, CURLOPT_POST, 1 );
      curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
      curl_setopt($ch,CURLOPT_FOLLOWLOCATION,1);

      // should curl return or print the data? true = return, false = print
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    }else{
      // should curl return or print the data? true = return, false = print
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      // remove header? 0 = yes, 1 = no
      curl_setopt($ch, CURLOPT_HEADER, 0);

      // timeout in seconds
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);

      $useragent="Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1";

      // user agent:
      curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
      
    }

    // set referer:
    curl_setopt($ch, CURLOPT_REFERER, "http://socialfinance.ca/");
 
    // download the given URL, and return output
    $output = curl_exec($ch);
 
    // close the curl resource, and free system resources
    curl_close($ch);
 
    // print output
    return $output;
}


// GET COMMENTS FROM EE MYSQL DATABASE
$sql = "SELECT comments.comment_id, comments.comment, comments.name, comments.email, comments.comment_date, titles.entry_id as eid,titles.title, titles.url_title, titles.entry_date FROM exp_comments AS comments LEFT JOIN {$titles_table} AS titles ON comments.entry_id = titles.entry_id WHERE comments.status = 'o' AND titles.status = 'open'";
$result = mysql_query($sql) or die(mysql_error());

while ($row = mysql_fetch_array($result)){
  
  // INIT
  $fields = array();
  $field_update = array();
  $article_url = $current_blog_base_url."/".$row['url_title']."/";
  $thread = ''; 
  
  // CHECK IF DISQUS THREAD EXISTS
  $thread_arr = json_decode(resourceCurl('/get_thread_by_url/?forum_api_key='.$forum_api_key.'&url='.$article_url),TRUE);      
  $thread = $thread_arr['message'];
      
  // IF DISQUS THREAD DOESN'T EXIST, CREATE IT
  if($thread == ''){  
    $fields['forum_api_key'] = $forum_api_key;
    $fields['identifier'] = $row['title'];
    $fields['title'] = $row['title'];
  
    $thread_arr = json_decode(resourceCurl($disqus_url.'/thread_by_identifier/',"POST",$fields),TRUE);    
    $thread = $thread_arr['message']['thread'];
    $field_update['forum_api_key'] = $forum_api_key;
    $field_update['thread_id'] = $thread['id'];
    $field_update['url'] = $article_url;
    
    // UPDATE THE DISQUS THREAD WITH THE CURRENT ARTICLE URL
    resourceCurl($disqus_url.'/update_thread/',"POST",$field_update);
  }
  
  // LETS IMPORT
  $field_create = array();
  
  $t = $row['comment_date'];
  $time = strftime("%Y-%m-%dT%H:%M",$t);
  $field_create['forum_api_key'] = $forum_api_key;
  $field_create['thread_id'] = $thread['id'];
  $field_create['message'] = ConvertCharacters($row['comment'], 'entity');
  $field_create['author_name'] = ConvertCharacters($row['name'], 'entity');
  $field_create['author_email'] = $row['email'];
  $field_create['created_at'] = $time;  
  $output  = json_decode(resourceCurl($disqus_url.'/create_post/',"POST",$field_create),TRUE);
  
  // ENABLE TO DEBUG OUTPUT
  //print_r($output);
  
}
?>