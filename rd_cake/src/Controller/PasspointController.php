<?php

namespace App\Controller;
use App\Controller\AppController;
use Cake\Http\Response;


class PasspointController extends AppController{

    protected $ca = <<<EOD
-----BEGIN CERTIFICATE-----
MIIEMjCCAxqgAwIBAgIBATANBgkqhkiG9w0BAQUFADB7MQswCQYDVQQGEwJHQjEb
MBkGA1UECAwSR3JlYXRlciBNYW5jaGVzdGVyMRAwDgYDVQQHDAdTYWxmb3JkMRow
GAYDVQQKDBFDb21vZG8gQ0EgTGltaXRlZDEhMB8GA1UEAwwYQUFBIENlcnRpZmlj
YXRlIFNlcnZpY2VzMB4XDTA0MDEwMTAwMDAwMFoXDTI4MTIzMTIzNTk1OVowezEL
MAkGA1UEBhMCR0IxGzAZBgNVBAgMEkdyZWF0ZXIgTWFuY2hlc3RlcjEQMA4GA1UE
BwwHU2FsZm9yZDEaMBgGA1UECgwRQ29tb2RvIENBIExpbWl0ZWQxITAfBgNVBAMM
GEFBQSBDZXJ0aWZpY2F0ZSBTZXJ2aWNlczCCASIwDQYJKoZIhvcNAQEBBQADggEP
ADCCAQoCggEBAL5AnfRu4ep2hxxNRUSOvkbIgwadwSr+GB+O5AL686tdUIoWMQua
BtDFcCLNSS1UY8y2bmhGC1Pqy0wkwLxyTurxFa70VJoSCsN6sjNg4tqJVfMiWPPe
3M/vg4aijJRPn2jymJBGhCfHdr/jzDUsi14HZGWCwEiwqJH5YZ92IFCokcdmtet4
YgNW8IoaE+oxox6gmf049vYnMlhvB/VruPsUK6+3qszWY19zjNoFmag4qMsXeDZR
rOme9Hg6jc8P2ULimAyrL58OAd7vn5lJ8S3frHRNG5i1R8XlKdH5kBjHYpy+g8cm
ez6KJcfA3Z3mNWgQIJ2P2N7Sw4ScDV7oL8kCAwEAAaOBwDCBvTAdBgNVHQ4EFgQU
oBEKIz6W8Qfs4q8p74Klf9AwpLQwDgYDVR0PAQH/BAQDAgEGMA8GA1UdEwEB/wQF
MAMBAf8wewYDVR0fBHQwcjA4oDagNIYyaHR0cDovL2NybC5jb21vZG9jYS5jb20v
QUFBQ2VydGlmaWNhdGVTZXJ2aWNlcy5jcmwwNqA0oDKGMGh0dHA6Ly9jcmwuY29t
b2RvLm5ldC9BQUFDZXJ0aWZpY2F0ZVNlcnZpY2VzLmNybDANBgkqhkiG9w0BAQUF
AAOCAQEACFb8AvCb6P+k+tZ7xkSAzk/ExfYAWMymtrwUSWgEdujm7l3sAg9g1o1Q
GE8mTgHj5rCl7r+8dFRBv/38ErjHT1r0iWAFf2C3BUrz9vHCv8S5dIa2LX1rzNLz
Rt0vxuBqw8M0Ayx9lt1awg6nCpnBBYurDC/zXDrPbDdVCYfeU0BsWO/8tqtlbgT2
G9w84FoVxp7Z8VlIMCFlA2zs6SFz7JsDoeA3raAVGI/6ugLOpyypEBMs1OUIJqsi
l2D4kF501KKaU73yqWjgom7C12yxow+ev+to51byrvLjKzg6CYG1a4XXvi3tPxq3
smPi9WIsgtRqAEFQ8TmDn5XpNpaYbg==
-----END CERTIFICATE-----
EOD;
  
    public function initialize():void{  
        parent::initialize();
        $this->Authentication->allowUnauthenticated([ 'androidProfile']);         
    }
      
    public function androidProfile(){
        $response   = $this->response;
        $response   = $response->withHeader('Content-Transfer-Encoding', 'base64');
        $response   = $response->withType('application/x-wifi-config');        
        $home_sp    = <<<EOD
<MgmtTree xmlns="syncml:dmddf1.2">
  <VerDTD>1.2</VerDTD>
  <Node>
    <NodeName>PerProviderSubscription</NodeName>
    <RTProperties>
      <Type>
        <DDFName>urn:wfa:mo:hotspot2dot0-perprovidersubscription:1.0</DDFName>
      </Type>
    </RTProperties>
    <Node>
      <NodeName>i001</NodeName>
      <Node>
        <NodeName>HomeSP</NodeName>
        <Node>
          <NodeName>FriendlyName</NodeName>
          <Value>WiFi-K9.5Jun24</Value>
        </Node>
        <Node>
          <NodeName>FQDN</NodeName>
          <Value>mesh-manager.com</Value>
        </Node>
      </Node>
      <Node>
        <NodeName>Credential</NodeName>
        <Node>
          <NodeName>Realm</NodeName>
          <Value>mesh-manager.com</Value>
        </Node>
        <Node>
          <NodeName>UsernamePassword</NodeName>
          <Node>
            <NodeName>Username</NodeName>
            <Value>ppsk_demo@ppsk_demo</Value>
          </Node>
          <Node>
            <NodeName>Password</NodeName>
            <Value>dGVzdGluZzEyMw==</Value>
          </Node>
          <Node>
            <NodeName>EAPMethod</NodeName>
            <Node>
              <NodeName>EAPType</NodeName>
              <Value>21</Value>
            </Node>
            <Node>
              <NodeName>InnerMethod</NodeName>
              <Value>MS-CHAP-V2</Value>
            </Node>
          </Node>
        </Node>
      </Node>
    </Node>
  </Node>
</MgmtTree> 
EOD;  
        $home_sp_64 = base64_encode($home_sp);  
        $ca_64      = base64_encode($this->ca);             
        $home_sp_ca = <<<EOD
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="XXXXboundary"
Content-Transfer-Encoding: base64

--XXXXboundary
Content-Type: application/x-x509-ca-cert
Content-Transfer-Encoding: base64

$ca_64

--XXXXboundary
Content-Type: application/x-passpoint-profile
Content-Transfer-Encoding: base64

$home_sp_64

--XXXXboundary--
EOD;       
                
        $response = $response->withStringBody(base64_encode($home_sp_ca));
        return $response;
    }
}
