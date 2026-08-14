<?php
//----------------------------------------------------------
//---- Author: Dirk van der Walt
//---- License: GPL v3
//---- Description: A component that helps with certain parts of WiFi reporting
//---- Date: 01-12-2024
//------------------------------------------------------------

namespace App\Controller\Component;
use Cake\Controller\Component;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

class WifiHelperComponent extends Component {


    public function getQualityGui($i){
    
        if($i->{'quality'}){
        
            $value = $i->{'quality'};
            if ($value >= 56) {
                $label = "Good";
            } elseif ($value >= 42) {
                $label = "Fair";
            } elseif ($value >= 28) {
                $label = "Poor";
            } else {
                $label = "Bad";
            }
            
            if ($value > 0) {
                $progressValue = $value / 70;
            } else {
                $progressValue = 0; // Default to 0 if total value is 0
            }

            // Format the value for display if needed
            $progressValue = number_format($progressValue, 2); // e.g., 0.50 for 50%
            
            $i->{'quality_bar'}   = $progressValue;
            $i->{'quality_human'} = $label;
        
        }  
    }

    public function getSignalGui($i){
        $minSignal = 0;
        $maxSignal = 0;
        $minProgress = 0.0;
        $maxProgress = 0.0;
        $label = '';

        if($i->{'noise'}){
            $signalLevel = $i->{'signal'}; 
            if ($signalLevel >= -50) {
                // Excellent Signal
                $minSignal = -50;
                $maxSignal = -30; // Cap upper bound for strong signal
                $minProgress = 0.75;
                $maxProgress = 1.0;
                $label = 'Excellent';
            } elseif ($signalLevel >= -60) {
                // Good Signal
                $minSignal = -60;
                $maxSignal = -50;
                $minProgress = 0.5;
                $maxProgress = 0.75;
                $label = 'Good';
            } elseif ($signalLevel >= -70) {
                // Weak Signal
                $minSignal = -70;
                $maxSignal = -60;
                $minProgress = 0.25;
                $maxProgress = 0.5;
                $label = 'Weak';
            } else {
                // Poor Signal
                $minSignal = -90; // Define lower bound for very poor signal
                $maxSignal = -70;
                $minProgress = 0.0;
                $maxProgress = 0.25;
                $label = 'Poor';
            }

            // Perform linear interpolation
            $progressValue = $minProgress + (($signalLevel - $minSignal) / ($maxSignal - $minSignal)) * ($maxProgress - $minProgress);
            
            if($signalLevel >= -30){
                $progressValue  = 1.0;
            }
            if($signalLevel <= -70){
                $progressValue  = 0.0;
            }

            $i->{'signal_bar'}   = $progressValue;
            $i->{'signal_human'} = $label;
            
        }
    }
	   
    public function getNoiseGui($i){
        $minNoise = 0;
        $maxNoise = 0;
        $minProgress = 0.0;
        $maxProgress = 0.0;
        $label = '';
        if($i->{'noise'}){
            $noiseLevel = $i->{'noise'};                        
            if ($noiseLevel <= -90) {
                // Quiet Environment
                $minNoise = -100;
                $maxNoise = -90;
                $minProgress = 0.75;
                $maxProgress = 1.0;
                $label = 'Quiet';
            } elseif ($noiseLevel <= -80) {
                // Moderate Environment
                $minNoise = -90;
                $maxNoise = -80;
                $minProgress = 0.5;
                $maxProgress = 0.75;
                $label = 'Moderate';
            } elseif ($noiseLevel <= -70) {
                // Noisy Environment
                $minNoise = -80;
                $maxNoise = -70;
                $minProgress = 0.25;
                $maxProgress = 0.5;
                $label = 'Noisy';
            } else {
                // Extremely Noisy Environment
                $minNoise = -70;
                $maxNoise = -60; // Define an upper bound for the worst case
                $minProgress = 0.0;
                $maxProgress = 0.25;
                $label = 'Extremely Noisy';
            }

            // Perform linear interpolation
            $progressValue = $minProgress + (($noiseLevel - $minNoise) / ($maxNoise - $minNoise)) * ($maxProgress - $minProgress);
            
            if($noiseLevel <= -100){
                $progressValue  = 1.0;
            }
            if($noiseLevel >= -60){
                $progressValue  = 0.0;
            }

            $i->{'noise_bar'}   = $progressValue;
            $i->{'noise_human'} = $label;
        }
    }
}
