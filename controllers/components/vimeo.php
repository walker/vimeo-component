<?php

vendor('phpVimeo');

class VimeoComponent extends Object {
	var $vimeo;

	// Set up your API key and secret.
	// Get these from your developer page at
	// http://www.vimeo.com/api
	var $api_key;
	var $api_secret;

	// That's all you need to do!
   
	// Cookie for the auth token
	var $cookiename = 'vimeo_auth_cookie';
	var $components = array('Cookie');
	
	// Sorry this is kind of a mess, but it shows you how
	// easy it is to get stuff done with the API.

	//Setup the basics
	function startup(&$controller) {
		// if(Configure::read('Vimeo.multiple')==false)
		// {
			error_reporting(E_ALL);
		
			$this->api_key = Configure::read('Vimeo.key');
			$this->api_secret = Configure::read('Vimeo.secret');
			$this->thevimeo = new phpVimeo($this->api_key, $this->api_secret, Configure::read('Vimeo.level'));
	
			$token = $this->Cookie->read($this->cookiename);
		
			if(@$_GET['catchfrob'] && @$_GET['frob']) {
				$rsp = $this->thevimeo->call('vimeo.auth.getToken', array('frob' => $controller->params['url']['frob']));
				if($rsp->stat == 'ok') {
					$token = $rsp->auth->token;
					$this->Cookie->write($this->cookiename, $token);
				}
				else {
					print_r($rsp);
					die("Some kind of error, probably invalid frob.");
				}
			}
		
			if($token) $this->thevimeo->setToken($token);
		
		
			// this calls checkToken if there is one.
			// it's probably a bit of overkill. you probably
			// just want to check the auth when you suspect it's out of whack.
			$this->thevimeo->auth(Configure::read('Vimeo.level'));
		// }
	}

	/* 
	 * 
	 */
	function connect() {
		$rsp = $this->thevimeo->auth(Configure::read('Vimeo.level'), true, true);
		
		return $rsp;
	}

	function formatObject($rsp_obj) {
		$return = get_object_vars($rsp_obj);
		foreach($return as $key=>$val) {
			if(is_object($val)) {
				$return[$key] = $this->formatObject($val);
			}
		}
		return $return;
	}

	function callMethod($method, $which='str', $_args=null) {
		if(!is_array($_args) && $_args!=null) {
			$_args = array($_args);
		} else if(!is_array($_args) && $_args==null) {
			$_args = array();
		}
		
		$args = $_args;
		
		$rsp = $this->thevimeo->call($method,  $args);
		
		if($which=='str')
		{
			if($rsp->stat == 'ok') {
				return true;
			} else {
				echo ($rsp->err->code. ": " . $rsp->err->msg . '<br />');
				return false;
			}
		} else {
			return $this->formatObject($rsp);
		}
	}

	//Save the information for a specific video
	function saveVideo($vars) {
		/* a quick convenience function that returns true on success
		 * or... well you get the idea. */
		foreach($vars as $key => $value)
		{
			if($key!='video_id')
			{
				$args = array('video_id'=>$vars['video_id'], $key=>$value);
				if($key=='tags') {
					$rsp = $this->thevimeo->call('vimeo.videos.clearTags', array('video_id'=>$video_id));
					$rsp = $this->thevimeo->call('vimeo.videos.addTags',  $args);
				} else {
					$rsp = $this->thevimeo->call('vimeo.videos.set'.ucfirst($key), $args);
				}
				
				if($rsp->stat == 'ok')				
					return true;
				else {
					echo ($rsp->err->code. ": " . $rsp->err->msg . '<br />');
					return false;
				}
			}
		}
	}

	//Grab the information for a specific video
	//96x72, 160x120, 100x75 and 200x150
	function getVideo($video_id, $width=null, $height=null) {
		$rsp = $this->thevimeo->call('vimeo.videos.getInfo', array('video_id' => $video_id));
		$video = $rsp->video;
		$tags = '';

		if(isset($video->tags) && is_array($video->tags->tag))  {
			foreach($video->tags->tag as $t)
				$tags .= $t->_content . ', ';
		}
		$tags = ereg_replace(", $", "", $tags);

		//$video->title
		//$video->height
		//$video->width
		//$video->caption
		//$video->title
		if($width && $height)
		{
			$r = $this->getThumb($video_id, $width, $height);
		} else if($width) {
			$r = $this->getThumb($video_id, $width);
		} else {
			$r = $this->getThumb($video_id);
		}
		
		$video->tagsStr = $tags;
		$video->thumbnail = $r->thumbnail;
		$video->genIn = $rsp->generated_in;
		
		return $this->formatObject($video);
	}

	//96x72, 160x120, 100x75 and 200x150
	function getThumb($video_id, $width="460", $height=null) {
		if($height) {
			return $this->thevimeo->call('vimeo.videos.getThumbnailUrl', array('video_id' => $video_id, 'size' => $width.'x'.$height));
		} else {
			return $this->thevimeo->call('vimeo.videos.getThumbnailUrl', array('video_id' => $video_id, 'size' => $width));
		}
	}
	
	//List the videos
	function listVideos() {
		$rsp = $this->thevimeo->call('vimeo.videos.getUploadedList'); // , array('user' => 'dog', 'page' => 1));
		if($rsp->stat == 'ok') {
			$videos = $rsp->videos;
			$videos->genIn = $rsp->generated_in;
			return $videos;
		} else {
			return false;
		}
	}
	
	/*
	* Accepts a video filename
	* Returns a string that is the ticket or false
	*/
	function uploadVideo($video_filename) {
		$ticket = $this->thevimeo->upload(WWW_ROOT.'files/videos/'.$video_filename);
		
		if(!$ticket) {
			return false;
		} else {
			return $ticket;
		}
	}
	
	function checkUpload($ticket_id) {
		$result = $this->callMethod('vimeo.videos.checkUploadStatus', 'arr', array('ticket_id'=>$ticket_id));
		return $result;
	}
	
	function setPrivacy($video_id, $priv = 'anybody') {
		$result = $this->callMethod('vimeo.videos.setPrivacy', 'arr', array('privacy'=>$priv, 'video_id'=>$video_id));
		if($result['stat']=='ok') {
			return true;
		} else {
			return false;
		}
	}
	
	function removeCachedVideo($video_filename) {
		@unlink(WWW_ROOT.'files/videos/'.$video_filename);
	}
	
	function deleteVideo($videoid) {
		$result = $this->callMethod('vimeo.videos.delete', 'str', array('video_id'=>$videoid));
		
		return $result;
	}

}

?>