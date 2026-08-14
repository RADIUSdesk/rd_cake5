#!/usr/bin/perl -w
use strict;
use warnings;
use POSIX;
use DateTime;
use Data::Dumper;
use feature 'say';

use vars qw(%RAD_REQUEST %RAD_REPLY %RAD_CHECK);

use constant RLM_MODULE_OK        => 2;
use constant RLM_MODULE_USERLOCK => 5;
use constant RLM_MODULE_NOOP     => 7;
use constant RLM_MODULE_UPDATED  => 8;

my $default_tz  = 'Africa/Johannesburg';
$default_tz     = 'Pacific/Tahiti'; # Override default if needed
my $return      = RLM_MODULE_NOOP;
my $cut_off     = 60;
my $dt;

sub authorize {

    # Reset per-request state (these are package globals!)
    $return = RLM_MODULE_NOOP;
    undef $dt;

    # Check for timezone override
    if (exists($RAD_CHECK{'Rd-Client-Timezone'}) && $RAD_CHECK{'Rd-Client-Timezone'} ne 'timezone_not_found') {
	    #        $default_tz = $RAD_CHECK{'Rd-Client-Timezone'};
    }

    if (exists($RAD_CHECK{'Login-Time'})) {
        my $login_time = $RAD_CHECK{'Login-Time'};
        do_logintime($login_time);

        if ($return == RLM_MODULE_USERLOCK) {
            $RAD_REPLY{'Reply-Message'} = "Not Available To Use On " . $dt->day_name . " at " . $dt->hms(':');
        }
    }

    return $return;
}

sub update_session_timeout {
    my ($remaining, $session_found) = @_;

    if ($remaining <= $cut_off || $remaining < 60) {
        return RLM_MODULE_USERLOCK;
    }

    if ($session_found) {
        if ($RAD_REPLY{'Session-Timeout'} >= $remaining) {
            $RAD_REPLY{'Session-Timeout'} = $remaining;
            return RLM_MODULE_UPDATED;
        }
    } else {
        $RAD_REPLY{'Session-Timeout'} = $remaining;
        return RLM_MODULE_UPDATED;
    }

    return RLM_MODULE_NOOP;
}

sub do_logintime {
    my ($login_time) = @_;

    my $session_found = exists($RAD_REPLY{'Session-Timeout'}) ? 1 : 0;
    $dt        = DateTime->now(time_zone => $default_tz);
    my $dt_start = $dt->clone;
    my $dt_end   = $dt->clone;

    my @slots      = split(/,|\|/, $login_time);
    my @week_days  = ('Mo', 'Tu', 'We', 'Th', 'Fr');

    $return = RLM_MODULE_USERLOCK; # Default: reject if Login-Time is set and doesn't match


	my @pending_days;

    foreach my $slot (@slots) {

        # If it's just a day, remember it
        if ($slot =~ m/^(Wk|Mo|Tu|We|Th|Fr|Sa|Su|Any|Al)$/) {
            push @pending_days, $1;
            next;
        }

        # If it's a full day+range (e.g., Th0000-2330)
        if ($slot =~ m/^(Wk|Mo|Tu|We|Th|Fr|Sa|Su|Any|Al)(\d{4}-\d{4})$/) {
            my $main_day  = $1;
            my $time_span = $2;
            my @all_days  = (@pending_days, $main_day);
            @pending_days = (); # clear

            say "$main_day is the Day - Got Time Span $time_span";

            my ($start, $end) = split("-", $time_span);
            my ($sh, $sm) = ($start =~ /(\d{2})(\d{2})/);
            my ($eh, $em) = ($end   =~ /(\d{2})(\d{2})/);

            if ("$eh$em" lt "$sh$sm") {
                ($sh, $eh) = ($eh, $sh);
                ($sm, $em) = ($em, $sm);
            }

            $dt_start->set_hour($sh);
            $dt_start->set_minute($sm);
            $dt_start->set_second(0);

            $dt_end->set_hour($eh);
            $dt_end->set_minute($em);
            $dt_end->set_second(0);

            say "Start: " . $dt_start->hms;
            say "End  : " . $dt_end->hms;
            say "Now  : " . $dt->hms;

            if ($dt->epoch >= $dt_start->epoch && $dt->epoch <= $dt_end->epoch) {
                my $now_day = substr($dt->day_name, 0, 2);
                my @week_days = ('Mo', 'Tu', 'We', 'Th', 'Fr');

                my %valid_days = map { $_ => 1 } map {
                    ($_ eq 'Any' || $_ eq 'Al') ? $now_day :
                    ($_ eq 'Wk') ? @week_days : $_
                } @all_days;

                if ($valid_days{$now_day}) {
                    say "IN THE SLOT CALCULATE THE RETURN $dt_end->hms $dt->hms";
                    my $remaining = $dt_end->epoch - $dt->epoch;
                    $return = update_session_timeout($remaining, $session_found);
                    return if $return == RLM_MODULE_USERLOCK;
                }
            }
        }
    }
}
