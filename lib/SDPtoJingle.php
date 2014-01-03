<?php
class SDPtoJingle {
    private $sdp;
    private $jingle;
    
    private $regex = array(
      'candidate' =>        "/^a=candidate:(\w{1,32}) (\d{1,5}) (udp|tcp) (\d{1,10}) ([a-zA-Z0-9:\.]{1,45}) (\d{1,5}) (typ) (host|srflx|prflx|relay)( (raddr) ([a-zA-Z0-9:\.]{1,45}) (rport) (\d{1,5}))?( (generation) (\d))?/i",
      'rtpmap' =>           "/^a=rtpmap:(\d+) (([^\s\/]+)\/(\d+)(\/([^\s\/]+))?)?/i",
      'fmtp' =>             "/^a=fmtp:(\d+) (.+)/i",
      'rtcp_fb' =>          "/^a=rtcp-fb:(\S+) (\S+)( (\S+))?/i",
      'rtcp_fb_trr_int' =>  "/^a=rtcp-fb:(\d+) trr-int (\d+)/i",
      'pwd' =>              "/^a=ice-pwd:(\S+)/i",
      'ufrag' =>            "/^a=ice-ufrag:(\S+)/i",
      'ptime' =>            "/^a=ptime:(\d+)/i",
      'maxptime' =>         "/^a=maxptime:(\d+)/i",
      'ssrc' =>             "/^a=ssrc:(\d+) (\w+)(:(\S+))?( (\w+))?/i",
      'rtcp_mux' =>         "/^a=rtcp-mux/i",
      'crypto' =>           "/^a=crypto:(\d{1,9}) (\w+) (\S+)( (\S+))?/i",
      'zrtp_hash' =>        "/^a=zrtp-hash:(\S+) (\w+)/i",
      'fingerprint' =>      "/^a=fingerprint:(\S+) (\S+)/i",
      'setup' =>            "/^a=setup:(\S+)/i",
      'extmap' =>           "/^a=extmap:([^\s\/]+)(\/([^\s\/]+))? (\S+)/i",
      'bandwidth' =>        "/^b=(\w+):(\d+)/i",
      'media' =>            "/^m=(audio|video|application|data)/i"
    );

    function __construct($sdp, $initiator, $responder, $action) {
        $this->sdp = $sdp;
        $this->jingle = new SimpleXMLElement('<jingle></jingle>');
        $this->jingle->addAttribute('xmlns', 'urn:xmpp:jingle:1');
        $this->jingle->addAttribute('action',$action);
        $this->jingle->addAttribute('initiator',$initiator);
        $this->jingle->addAttribute('responder',$responder);
        $this->jingle->addAttribute('sid', generateKey(10));
    }
    
    function createContent() {
        if(!$this->jingle->content)
            return $this->jingle->addChild('content');
        else 
            return $this->jingle->content;
    }
    function createTransport() {
        $content = $this->createContent();
        if(!$content->transport) {
            $transport = $content->addChild('transport');
            $transport->addAttribute('xmlns', "urn:xmpp:jingle:transports:ice-udp:1");
            return $transport;
        }
        else 
            return $content->transport;
    }

    function generate() {
        $arr = explode("\n", $this->sdp);
        
        foreach($arr as $l) {
            foreach($this->regex as $key => $r) {
                if(preg_match($r, $l, $matches)) {
                    switch($key) { 
                        case 'media':
                            $media = array(
                                'media'         => $matches[1]
                                );
                                
                            $content = $this->jingle->addChild('content');
                            $content->addAttribute('creator', 'initiator'); // TODO à fixer !
                            $content->addAttribute('name', $media['media']);
                            
                            // The description node
                            $description = $content->addChild('description');
                            $description->addAttribute('xmlns', "urn:xmpp:jingle:apps:rtp:1");
                            $description->addAttribute('media', $media['media']);
                            
                            // The transport node
                            $transport = $this->createTransport();
                            break;
                            
                        case 'bandwidth':
                            $bandwidth = $description->addChild('bandwidth');
                            $bandwidth->addAttribute('type',       $matches[1]);
                            $bandwidth->addAttribute('value',      $matches[2]);
                            break;
                            
                        // http://xmpp.org/extensions/xep-0167.html#format
                        case 'fmtp':
                            // TODO : complete it
                            break;
                            
                        case 'rtpmap':
                            if(isset($matches[6]))
                                $channel = $matches[6];
                            else $channel = null;
                        
                            $payloadtype = $description->addChild('payload-type');
                            $payloadtype->addAttribute('id',        $matches[1]);
                            $payloadtype->addAttribute('name',      $matches[3]);
                            $payloadtype->addAttribute('clockrate', $matches[4]);
                            
                            if($channel)
                                $payloadtype->addAttribute('channel',   $channel);
                            
                            break;
                            
                        case 'rtcp_fb':
                            if($matches[1] == '*') {
                                $rtcpfp = $description->addChild('rtcp-fp');
                            } else { 
                                $rtcpfp = $payloadtype->addChild('rtcp-fp');
                            }
                            $rtcpfp->addAttribute('xmlns', "urn:xmpp:jingle:apps:rtp:rtcp-fb:0");
                            $rtcpfp->addAttribute('id',        $matches[1]);
                            $rtcpfp->addAttribute('type',      $matches[2]);
                            
                            if(isset($matches[4]))
                                $rtcpfp->addAttribute('subtype',   $matches[4]);
                            
                            break;
                            
                        case 'rtcp_fb_trr_int':
                            $rtcpfp = $payloadtype->addChild('rtcp-fb-trr-int');
                            $rtcpfp->addAttribute('xmlns', "urn:xmpp:jingle:apps:rtp:rtcp-fb:0");
                            $rtcpfp->addAttribute('id',        $matches[1]);
                            $rtcpfp->addAttribute('value',     $matches[2]);
                            break;
                            
                        // http://xmpp.org/extensions/xep-0167.html#srtp
                        case 'crypto':
                            $encryption = $description->addChild('encryption');
                            $crypto     = $encryption->addChild('crypto');
                            $crypto->addAttribute('crypto-suite',   $matches[2]);
                            $crypto->addAttribute('key-params',     $matches[3]);
                            $crypto->addAttribute('tag',            $matches[1]);
                            if(isset($matches[5]))
                                $crypto->addAttribute('session-params', $matches[5]);
                            break;
                        
                        // http://xmpp.org/extensions/xep-0262.html
                        case 'zrtp-hash':
                            $zrtphash     = $encryption->addChild('zrtp-hash', $matches[2]);
                            $zrtphash->addAttribute('xmlns',   "urn:xmpp:jingle:apps:rtp:zrtp:1");
                            $zrtphash->addAttribute('version',   $matches[1]);
                            break;
                            
                        case 'rtcp_mux':
                            $description->addChild('rtcp-mux');
                            break;

                        // http://xmpp.org/extensions/xep-0294.html
                        case 'extmap':
                            $rtphdrext = $description->addChild('rtp-hdrext');
                            $rtphdrext->addAttribute('xmlns',   "urn:xmpp:jingle:apps:rtp:rtp-hdrext:0");
                            $rtphdrext->addAttribute('id',      $matches[1]);
                            $rtphdrext->addAttribute('uri',     $matches[4]);
                            $rtphdrext->addAttribute('senders', $matches[4]);
                            break;
                            
                        // http://xmpp.org/extensions/inbox/jingle-source.html
                        case 'ssrc':
                            if(!$description->source) {
                                $ssrc = $description->addChild('source');
                                $ssrc->addAttribute('xmlns',   "urn:xmpp:jingle:apps:rtp:ssma:0");
                                $ssrc->addAttribute('id',   $matches[1]);
                            }
                            
                            $param = $ssrc->addChild('parameter');
                            $param->addAttribute('name',   $matches[2]);
                            $param->addAttribute('value',  $matches[4]);
                            break;
                            
                        case 'ptime':
                            $description->addAttribute('ptime', $matches[1]);
                            break;
                            
                        case 'maxptime':
                            $description->addAttribute('maxptime', $matches[1]);
                            break;
                            
                        // À appeler à la fin
                        case 'fingerprint':
                            $transport = $this->createTransport();
                            if(!$transport->fingerprint) {
                                $fingerprint = $transport->addChild('fingerprint', $matches[2]);
                                $fingerprint->addAttribute('xmlns', "urn:xmpp:jingle:apps:dtls:0");
                                $fingerprint->addAttribute('hash', $matches[1]);
                            }
                            break;
                            
                        case 'setup':
                            if(!$fingerprint->attributes()->setup)
                                $fingerprint->addAttribute('setup', $matches[1]);
                            break;
                            
                        case 'pwd': 
                            $transport = $this->createTransport();
                            if(!$transport->attributes()->pwd)
                                $transport->addAttribute('pwd', $matches[1]);
                            break;
                            
                        case 'ufrag':
                            $transport = $this->createTransport();
                            if(!$transport->attributes()->ufrag)
                                $transport->addAttribute('ufrag', $matches[1]);                            
                            break;
                        
                        case 'candidate':
                            if(isset($match[16]))
                                $generation = $matches[16];
                            else
                                $generation = 0;
                                
                            if(isset($matches[11]) && isset($matches[13])) {
                                $reladdr = $matches[11];
                                $relport = $matches[13];
                            } else {
                                $reladdr = $relport = null;
                            }
                            
                            $candidate = $transport->addChild('candidate');
                        
                            $candidate->addAttribute('component' , $matches[2]);
                            $candidate->addAttribute('foundation', $matches[1]);
                            $candidate->addAttribute('generation', $generation); //|| JSJAC_JINGLE_GENERATION;
                            $candidate->addAttribute('id'        , generateKey(10)); //$self.util_generate_id();
                            $candidate->addAttribute('ip'        , $matches[5]);
                            $candidate->addAttribute('network'   , 0);
                            $candidate->addAttribute('port'      , $matches[6]);
                            $candidate->addAttribute('priority'  , $matches[4]);
                            $candidate->addAttribute('protocol'  , $matches[3]);
                            $candidate->addAttribute('type'      , $matches[8]);
                            
                            if($reladdr) {
                                $candidate->addAttribute('rel-addr'  , $reladdr);
                                $candidate->addAttribute('rel-port'  , $relport);
                            }
                            break;
                    }
                }
            }
        }
        
        // We reindent properly the Jingle package
        $xml = $this->jingle->asXML();
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $doc->formatOutput = true;
        
        return substr($doc->saveXML() , strpos($doc->saveXML(), "\n")+1 );
    }
}
