<?php

require_once(dirname(__FILE__) . '/../constant.php');

define('PLUGINID', 'com.synology.TheTVDB');
define('API_URL', 'https://api.douban.com/v2/movie/');
define('DEFAULT_EXPIRED_TIME', 86400);
define('DEFAULT_LONG_EXPIRED_TIME', 30*86400);

function getImdbID($input) {
    preg_match_all('/<([^\s\/]+)[^>]*imdb\.com[^>]*(rel|property)="nofollow"[^>]*>([^<]*?)<\/\1>/', $input, $matches);
    return implode("", $matches[3]);
}

function RegexByRel($rel, $input) {
    preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*(rel|property)="' . $rel . '"[^>]*>([^<]*?)<\/\1>/', $input, $matches);
    return $matches[3];
}

function getSeasonsList($input) {
  $seasons = array();
  preg_match_all('/<(select) id=.season.[\s\S]*?((<(option) value=(\d+)[^>]+>(\d+)<\/\4>)+)[\s\S]*?<\/\1>/', $input, $matches);
  if(! empty($matches)) {
    $target = implode("", $matches[2]);
    preg_match_all('/<(option) value=(\d+)[^>]+>(\d+)<\/\1>/', $target, $matches);
    if(! empty($matches)) {
      $cnt = 0;
      foreach( $matches[3] as $index) {
        $seasons[$index - 1] = $matches[2][$cnt++];
      }
    }
  }
  ksort($seasons);
  return $seasons;
}

function getTagline($input) {
  preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*>本集中文名:<\/\1>[^<]*<([^\s\/]+)(?=[^>]*>)[^>]*>([^<]*)<\/\2>/', $input, $matches);
  if(! empty($matches[3])) {
    return implode("", $matches[3]);
  } else {
    return NULL;
  }
}

function getEpisodeDate($input) {
  preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*>播放时间:<\/\1>[^<]*<([^\s\/]+)(?=[^>]*>)[^>]*>([^<]*)<\/\2>/', $input, $matches);
  if(! empty($matches[3])) {
    return implode("", $matches[3]);
  } else {
    return NULL;
  }
}

function getEpisodeSummary($input) {
  preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*>剧情简介:<\/\1>[^<]*<([^\s\/]+)(?=[^>]*>)[^>]*>[^<]*<([^\s\/]+)(?=[^>]*>)[^>]*>([^<]*)<\/\3>/', $input, $matches);
  if(! empty($matches[4])) {
    return trim(implode("",$matches[4]));
  } else {
    return NULL;
  }
}

function getWriter($input) {
    preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*>[\s]*<([^\s\/]+)(?=[^>]*>)[^>]*>编剧<\/\2>[\s\S]*?<([^\s\/]+)(?=[^>]*>)[^>]*>([\s\S]*?)<\/\3><\/\1>/', $input, $target);
    $target = implode("", $target[4]);
    preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*>([\s\S]*?)<\/\1>/', $target, $matches);
    return $matches[2];
}

function getBackdrop($input) {
    preg_match_all('/<([^\s\/]+)(?=[^>]*>)[^>]*class="related-pic-bd[^>]*"[^>]*>[\s\S]*?\/photos\/photo\/(\d+)\/[\s\S]*?<\/\1>/', $input, $matches);
    return implode("", $matches[2]);
}

function getRegexDate($input) {
    if( is_array($input) ) {
        $input = implode(";", $input);
    }
    preg_match('/\d{4}-\d{2}-\d{2}/', $input, $matches);
    if(empty($matches)) {
      preg_match('/\d{4}-\d{2}/', $input, $matches);
    }
    if(empty($matches)) {
      preg_match('/\d{4}/', $input, $matches);
    }
    return $matches[0];
}

function getDoubanRawData($title, $limit = 20) {
    $title = urlencode($title);
    return json_decode( HTTPGETRequest( API_URL . "search?q={$title}&count={$limit}" ) , true);
}

function getDoubanTvData($id) {
    // tv info from douban api
    $cache_path = GetPluginDataDirectory(PLUGINID) . "/{$id}/tvInfo.json";
    $url = API_URL . "subject/{$id}";
    $ret = DownloadTvData($url, $cache_path);

    // add-on info
    $cache_path = GetPluginDataDirectory(PLUGINID) . "/{$id}/addon.json";
    $url = "https://movie.douban.com/subject/{$id}/";
    return DownloadAddOnInfo($url, $cache_path, $ret);
}

function getEpisodeData($id, $episode, $season_data) {
  $cache_path = GetPluginDataDirectory(PLUGINID) . "/${id}/episode_{$episode}.json";
  $url = "https://movie.douban.com/subject/{$id}/episode/{$episode}/";
  return DownloadEpisodeInfo($url, $cache_path, $episode, $season_data);
}

function getDoubanTvSeriesData($tv_data) {
  $series_data = array();
  if(!isset($tv_data->seasons_count)) {
    $tv_data->season_count = 1;
    $episodes = array(
      'season' => $i + 1
    );
    for($j = 0; $j < $tv_data->episodes_count; $j++) {
      $episodes['episode'][] = getEpisodeData($id, $j+1, $tv_data);
    }
    $series_data[] = $episodes;
  } else {
    for($i = 0; $i < $tv_data->seasons_count; $i++) {
      $id = $tv_data->seasonid[$i];
      if($tv_data->current_season != ($i + 1)) {
        $season_data = getDoubanTvData($id);
      } else {
        $season_data = $tv_data;
      }
      $episodes = array(
        'season' => $i + 1
      );
      for($j = 0; $j < $season_data->episodes_count; $j++) {
        $episodes['episode'][] = getEpisodeData($id, $j+1, $season_data);
      }
      $series_data[] = $episodes;
    }
  }
  return $series_data;
}

function getDataFromCache($cache_path) {
	$json = FALSE;

	//Whether cache file already exist or not
	if (file_exists($cache_path)) {
		$lastupdated = filemtime($cache_path);
		if (DEFAULT_EXPIRED_TIME >= (time() - $lastupdated)) {
			$json = json_decode(@file_get_contents($cache_path));
			if (NULL !== $json) {
				return $json;
			}
		}
    }
    
    return FALSE;
}

function refreshCache ($data, $cache_path) {
    //create dir
    $path_parts = pathinfo($cache_path);
    if (!file_exists($path_parts['dirname'])) {
        mkdir($path_parts['dirname']);
    }

    //write
    @file_put_contents($cache_path, json_encode($data));

    if (FALSE === $data || NULL === $data) {
        @unlink($cache_path);
    }
    return $data;
}

function DownloadTvData($url, $cache_path) {
	$json = getDataFromCache($cache_path);

	//If we need refresh cache file, grab rawdata from url website
	if (FALSE === $json) {
        $json = json_decode(HTTPGETRequest($url));
        refreshCache($json, $cache_path);
    }

	return $json;
}

function DownloadAddOnInfo ($url, $cache_path, $ret) {
    $json = getDataFromCache($cache_path);

    //If we need refresh cache file, grab rawdata from url website
	if (FALSE === $json) {
        $html = HTTPGETRequest($url);
        $json = array();
        $json['original_available'] = getRegexDate(RegexByRel('v:initialReleaseDate', $html));
        $json['imdb'] = getImdbID($html);
        $json['backdrop'] = 'https://img3.doubanio.com/view/photo/photo/public/p' . getBackdrop($html) . '.jpg';
        $json['genres'] = RegexByRel('v:genre', $html);
        $json['casts'] = RegexByRel('v:starring', $html);
        $json['writers'] = getWriter($html);
        $json['seasonid'] = getSeasonsList($html);
        refreshCache($json, $cache_path);
    }

    foreach($json as $key => $val) {
        $ret->$key = $val;
    }

    return $ret;
}

function DownloadEpisodeInfo ($url, $cache_path, $episode, $season_data) {
    $json = getDataFromCache($cache_path);

    //If we need refresh cache file, grab rawdata from url website
	if (FALSE === $json) {
        $html = HTTPGETRequest($url);
        $json = array();
        $json['season'] = $season_data->current_season;
        $json['episode'] = $episode;
        $json['tagline'] = getTagline($html);
        $json['original_available'] = getEpisodeDate($html);
        $json['summary'] = getEpisodeSummary($html);
        $json['certificate'] = $season_data->certificate;
        $json['actor'] = $season_data->casts;
        $json['genre'] = $season_data->genres;
        $json['extra'] = array();
        $json['extra'][PLUGINID] = array('reference' => array());
        $json['extra'][PLUGINID]['reference']['thetvdb'] = $season_data->id;
        $json['extra'][PLUGINID]['reference']['imdb'] = $season_data->imdb; // add-on
		$json['extra'][PLUGINID]['rating'] = array('thetvdb' => $season_data->rating->average);
        $json['extra'][PLUGINID]['poster'] = NULL;
        refreshCache($json, $cache_path);
    }

    return $json;
}
