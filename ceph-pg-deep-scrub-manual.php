#!/usr/bin/php 
<?php

/***
Execute with ./ceph-pg-deep-scrub-manual.php
or
/usr/bin/php ./ceph-pg-deep-scrub-manual.php

Also make sure you set date.timezone in /etc/php.ini before starting

It might make sense to add this to your crontab while your ceph cluster has too many outstanding deep-scrubs.
this will add one deep scrub every two minutes
*/2 * * * * /root/scripts/ceph-pg-deep-scrub-manual.php  > /dev/null 2>&1

***/


/*
This script is originally built by
https://gist.github.com/ethaniel/5db696d9c78516308b235b0cb904e4ad
Modified to accomodate
- max deep-scrub age -> 1 weeks
- change line breaks 

Original Text: 
Script to always have 1 deep scrub running per OSD.
## About
1. Helps with the following error: PG_NOT_DEEP_SCRUBBED HEALTH_WARN (N pgs not deep-scrubbed in time)
2. Doesn't run scrubbing on PGs that were deep scrubbed less than 1  weeks ago, releasing
resources to the regular scheduler scrubber which might take the chance to do a light scrub instead.
## Suggestions
1. Add to crontab to run automatically. It's OK to start this script once per minute, since if all OSDs are
busy with scrubbing, it will simply exit.
* * * * * /usr/bin/php /ceph_deep_scrub.php
2. Run the following commands to lower the scrubbing priority, so the OSDs won't get overloaded with them:
ceph config set osd osd_scrub_priority 1
ceph config set osd osd_max_scrubs 1
3. Run the following command to disable emails about PGs not being scrubbed in time:
ceph config set mgr mon_warn_pg_not_deep_scrubbed_ratio 0
ceph config set mgr mon_warn_pg_not_scrubbed_ratio 0
4. You can check the list of active scrubs via:
ceph pg dump | grep scrub
*/



// array which keeps the last deep scrub for PGs
$last_scrub = array();

// array which keeps OSDs which are currently have running scrubs
$block_osd = array();

// array which maps PGs to OSDs
$pg_osd = array();


// Receive PG data
$data = `ceph pg dump`;

// Get columns headers
preg_match('#^(PG_STAT.*?)$#um',$data,$r);
$header = preg_split('#\s+#',$r[0]);

// Get rows with PG data
preg_match_all('#^([0-9a-f]+\.[0-9a-f]+.*?)$#um',$data,$r);

// Run through each PG
foreach ($r[1] as $row) {
 $row = preg_split('#\s+#',$row);

 // make sure that we actually have a row with PG data (number of elements matches number of elements in header)
 if (count($row) == count($header)) {

  // create temporary array which maps column headers to actual PG data
  $arr = array_combine($header,$row);


  if ($arr["STATE"] == "active+clean") { // if PG is healthy and not being scrubbed right now

   // store last scrub time for PG
   $last_scrub[$arr["PG_STAT"]] = $arr["DEEP_SCRUB_STAMP"];

   $osd = array();

   // map PG to it's OSDs
   preg_match_all('#(\d+)#',$arr["ACTING"],$osd);
   foreach ($osd[1] as $t) {
    $pg_osd[$arr["PG_STAT"]][] = $t;
   }
  } else { // PG is not healthy OR being scrubbed
   $osd = array();

   // add it's OSDs to the stoplist
   preg_match_all('#(\d+)#',$arr["ACTING"],$osd);
   foreach ($osd[1] as $t) {
    $block_osd[$t] = 1;
   }
  }

 }
}

// sort PGs by last deep scrub time (oldest first)
asort($last_scrub);

// sort OSD stoplist by number (just for beauty)
asort($block_osd);
// echo "List of OSDs that are not in active+clean status (we won't start scrubbing on them): ";
// echo join(", ",$block_osd);
// echo "\n\n";

// get PGs with oldest scrubs first
foreach ($last_scrub as $pg=>$time) {


 echo "pg:".$pg;
 echo "\t";
 echo "last_deep_scrub:".$time;
 echo "\t";
 echo "osds:".join(",",$pg_osd[$pg]);
 echo "\t";
 echo "\n";
 if (strtotime($time) > strtotime("-1 weeks")) {
  echo "already scrubbed not too long ago\n";
  // go look for another PG which might be more suitable
  continue;
 }

 // check which OSDs the PG sits on
 foreach ($pg_osd[$pg] as $osd) {

  // if one of the OSDs the PG sits on is currently busy, then don't do anything on this PG
  if (array_key_exists($osd,$block_osd)) {
   echo "osd blocked:$osd\n";
   // go look for another PG which might be more suitable
   continue 2;
  }
 }

 echo "osds not blocked, starting deep scrub\n";
 `ceph pg deep-scrub $pg`;

 // exiting here, because the PG status has already updated. Need to run script again if you want to start another deep scrub.
 // I suggest running the script with crontab every minute.
 exit;
}

// normally, we reach this point only if all OSDs are busy (are not in active+clean status) and we haven't started any new deep scrubs.
