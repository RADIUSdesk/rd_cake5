#! /usr/bin/perl -w
use strict;
use POSIX;
use warnings;
use DateTime;
use Data::Dumper;
use feature 'say';

# use ...
# This is very important !
use vars qw(%RAD_REQUEST %RAD_REPLY %RAD_CHECK);
use constant RLM_MODULE_OK=> 2; # /* the module is OK,continue */
use constant RLM_MODULE_USERLOCK=>  5;#  /* reject the request (user is locked out) */
use constant RLM_MODULE_NOOP=> 7;
use constant RLM_MODULE_UPDATED=> 8; # /* OK (pairs modified) */

my $default_tz  = 'Africa/Johannesburg';
$default_tz = 'Pacific/Tahiti';

sub authorize {

    my $dt_d_start  = DateTime->now( time_zone => $default_tz);
    my $dt_m_start  = DateTime->now( time_zone => $default_tz);
    $dt_d_start->set(hour => 00, minute => 00, second => 00);
    $dt_m_start->set(day => 1,hour => 00, minute => 00, second => 00);
    
    $RAD_CHECK{'Rd-Adv-Day-Start'}      = $dt_d_start->epoch;
    $RAD_CHECK{'Rd-Adv-Month-Start'}    = $dt_m_start->epoch;
    my $return = RLM_MODULE_NOOP;   
    return $return;
}



