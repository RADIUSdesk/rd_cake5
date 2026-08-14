#! /usr/bin/perl -w
use strict;
use POSIX;
use DateTime;

# use ...
# This is very important !
use vars qw(%RAD_CHECK);
use constant RLM_MODULE_OK=> 2; # /* the module is OK,continue */
use constant RLM_MODULE_NOOP=> 7;
use constant RLM_MODULE_UPDATED=> 8; # /* OK (pairs modified) */

sub authorize {
 
    $RAD_CHECK{'Rd-Data-Start-Time'} = start_of_month($RAD_CHECK{'Rd-Data-Limit-Reset-On'},$RAD_CHECK{'Rd-Data-Limit-Reset-Hour'},$RAD_CHECK{'Rd-Data-Limit-Reset-Min'});
       
    if(exists($RAD_CHECK{'Rd-Data-Start-Time'})){
        return RLM_MODULE_UPDATED;    
    }else{
        return RLM_MODULE_NOOP;
    }
}

sub start_of_month {
    #Send it the day, hour and minute to 
    my($r_day,$r_hour,$r_min) = @_;
    my $dt_now = DateTime->now;
    
    #Do this for values where $r_day is 31 and month only has 30 days
    my $last_day_of_month =  DateTime->last_day_of_month( year => $dt_now->year, month => $dt_now->month)->day;
    if($r_day > $last_day_of_month){
        $r_day = $last_day_of_month;
    }
    
    #Get the day of the month at this moment in time
    my $day_now = $dt_now->day;
    
    my $dt_reset  = DateTime->new(
        year       => $dt_now->year,
        month      => $dt_now->month,
        day        => $r_day,
        hour       => $r_hour,
        minute     => $r_min
    );
    
    if($dt_now->epoch() < $dt_reset->epoch()){  #We use the previous month 
        $dt_reset = $dt_reset->clone->add(months => -1, end_of_month => 'preserve');
    }
    #print("NOW IS   ".$dt_now->strftime("%F %T")."\n");
    #print("RESET ON ".$dt_reset->strftime("%F %T")."\n");
    return $dt_reset->epoch();
}


