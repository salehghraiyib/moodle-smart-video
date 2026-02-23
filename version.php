<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_smartvideo';
$plugin->version   = 2025011411; // YYYYMMDDxx
$plugin->requires  = 2022112800; // Moodle 4.1+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.1';
$plugin->cron      = 60; // Run tasks every 60 seconds