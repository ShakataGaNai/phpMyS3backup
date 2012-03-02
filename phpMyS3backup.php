<?php

require_once 'config.inc.php';
if(DEBUG_ON){error_reporting(-1);}
$now = date("Ymd_His");
syslog(LOG_INFO, "phpMyS3Backup - Starting run ID $now");
require_once 'AWSSDKforPHP/sdk.class.php';


// ********************************
// Get list of databases
// ********************************
$alldb = array();
$GLOBALS['con'] = mysqli_connect(DB_HOST, DB_USER, DB_PASS) or die("Cannot Connect to MySQL");
$res = mysqli_query($GLOBALS['con'], "SHOW DATABASES;");
while($row = mysqli_fetch_array($res)){
	if($row['Database'] != "information_schema"){
		$alldb[] = $row['Database'];
		deb("Database found: {$row['Database']}");
	}
}

// ********************************
// MySQLDump each database
// ********************************
system("mkdir /tmp/$now/");
foreach($alldb as $db){
	$cmd = "mysqldump $db -h ".DB_HOST." -u".DB_USER." -p".DB_PASS." > /tmp/{$now}/$db.sql";
	deb("Doing: $cmd");
	system($cmd);
}

// ********************************
// gzip databases
// ********************************
foreach($alldb as $db){
	system("gzip /tmp/{$now}/$db.sql");
}

// ********************************
// Upload the files to S3
// ********************************
$aws = array( 	'key' => AWS_KEY,
		'secret' => AWS_SECRET,
		'default_cache_config' => '',
		'certificate_authority' => false
		);

$s3 = new AmazonS3($aws);
$bucket = strtolower('phpmys3backup-'.SRV_NAME.'-'.substr(sha1($s3->key), 0, 10) );
$create_bucket_response = $s3->create_bucket($bucket, AmazonS3::REGION_US_E1);
deb("creating bucket");
if ($create_bucket_response->isOK()) {
	$exists = $s3->if_bucket_exists($bucket);
	while (!$exists) {
		sleep(1);
		$exists = $s3->if_bucket_exists($bucket);
	}

	deb("bucket created - doing file add");

	foreach ($alldb as $db) {
		$filename = "$db.sql.gz";
		$path = "/tmp/{$now}/";

		$s3->batch()->create_object($bucket, $now."/".$filename, array(
			'fileUpload' => $path.$filename,
			'storage' => AmazonS3::STORAGE_REDUCED,
			'acl' => AmazonS3::ACL_PRIVATE,
			'encryption' => 'AES256'
		));
	}

	deb("files setup done - uploading");

	$file_upload_response = $s3->batch()->send();

	deb("upload done - waiting for ok");

	if(DEBUG_ON){
		if ($file_upload_response->areOK()) {
			foreach ($alldb as $db) {
				print $s3->get_object_url($bucket, $now."/".$db.".sql.gz", '5 minutes') . PHP_EOL . PHP_EOL;
			}
		}
	}
}

// ********************************
// Cleanup the local filesystem
// ********************************

system("rm -rf /tmp/{$now}/");
deb("DONE");
syslog(LOG_INFO, "phpMyS3Backup - completed run ID $now");


// ********************************
// Done!
// ********************************



function deb ($msg) {
	if(DEBUG_ON) { print $msg . "\n"; }
}
