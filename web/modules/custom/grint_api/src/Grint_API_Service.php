<?php

namespace Drupal\grint_api;

use DOMDocument;
use DOMXPath;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Site\Settings;

class Grint_API_Service {
  protected $client;
  protected ConfigFactoryInterface $configFactory;
  protected \Drupal\Core\Logger\LoggerChannelInterface $logger;
  protected ClientInterface $httpClient;
  protected StateInterface $state;
  protected $login_url = 'https://www.thegrint.com';

  public function __construct(ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerFactory, ClientInterface $httpClient, StateInterface $state) {
    $this->configFactory = $configFactory;
    $this->logger = $loggerFactory->get('Grint_API');
    $this->httpClient = $httpClient;
    $this->state = $state;
    $this->client = new Client([
      'base_uri' => 'https://www.thegrint.com',
      'cookies' => true,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded'
      ]
    ]);
    $this->login();
  }

  public function login() {
    $response = $this->client->post('/login', [
      'form_params' => [
        'username' => Settings::get('grint_api.settings')['username'],
        'password' => Settings::get('grint_api.settings')['password'],
      ]
    ]);
    return $response;
  }

  public function getHandicapIndex($grint_user_id) {
    $uri = '/user/get_handicap_info/';
    $payload = ['user_id' => $grint_user_id];
    $index = $this->postRequest($uri, $payload);

    $clean = trim($index->index_ghap, "~");

    if (preg_match('/\d+(\.\d+)?/', $clean, $matches)) {
      $index_ghap = (float) $matches[0];
    }

    if (strpos($index_ghap, '+') === 0) {
      $hdcp = -floatval(substr($index_ghap, 1));
    }
    else {
      $hdcp = floatval($index_ghap);
    }
    return $hdcp;
  }

  public function getRequest($uri) {
    $response = $this->client->get($uri);
    return $response->getBody()->getContents();
  }

  public function postRequest($uri, $payload = null) {
    $response = $this->client->post($uri, [
      'form_params' => $payload
    ]);
    return json_decode($response->getBody()->getContents());
  }

  public function postRequestHTML($uri, $payload) {
    $response = $this->client->post($uri, [
      'form_params' => $payload
    ]);
    return ($response->getBody()->getContents());
  }

  public function searchCourse($string) {
    $uri = '/ajax/courseAutoComplete';
    $payload = [
      'search' => $string,
      'wave' => 0,
      'limit' => 10,
    ];
    $courses = $this->postRequest($uri, $payload);
    return $courses;
  }

  public function getCourseIdFromString($string) {
    if (preg_match('/^\((\d+)\)/', $string, $matches)) {
      return $matches[1];
    }
    return 0;
  }

  public function searchCourseTeeColors($course_id) {
    $uri = '/score/ajax_tees/';
    $payload = [
      'user_id' => 1597150,
      'course_id' => $course_id,
      'tee' => 'magenta',
    ];
    $teeHTML = $this->postRequestHTML($uri, $payload);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($teeHTML);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $options = $xpath->query('//option[@class="option-tee"]');

    $results = [];
    foreach ($options as $option) {
      $value = $option->getAttribute('value');
      $results[$value] = [
        'value' => $value,
        'lr' => $option->getAttribute('lr'),
        'ls' => $option->getAttribute('ls'),
        'mr' => $option->getAttribute('mr'),
        'ms' => $option->getAttribute('ms'),
      ];
    }

    return $results;
  }

  public function getCourseData($course_id, $tee_color, $round = 18, $handicap_company_id = 7){
    $uri = '/ajax/get_course_data/0/0/0';
    $payload = [
      'course_id' => $course_id,
      'tee' => $tee_color,
      'round' => $round,
    ];
    return $this->postRequest($uri, $payload);
  }

  public function processCourseData($course_data){
    $clean_data = [];
    $clean_data['handicap'] = $this->processHandicap($course_data->handicap);
    $clean_data['yardage'] = $this->processYardages($course_data->yardage);
    $clean_data['par'] = $this->processPar($course_data->par);
    return $clean_data;
  }

  public function getGrintProfileImg($ghap_id){
    $uri = '/user/ajax_search_users_json';
    $payload = [
      'search' => $ghap_id,
    ];
    return $this->postRequest($uri, $payload)[0]->image;
  }

  public function getCourseHandicap($user_id, $user_hdcp, $course_id, $tee_color) {
    $uri = '/user/ajax_course_hdcp_lookup/';
    $payload = [
      'user_id' => $user_id,
      'user_hdcp' => $user_hdcp,
      'course_id' => $course_id,
      'tee' => $tee_color,
      'provider' => 7,
    ];
    return $this->postRequest($uri, $payload);
  }

  public function getCourseHandicapManual($handicapIndex, $slopeRating) {
    $courseHandicap = $handicapIndex * ($slopeRating / 113);
    return round($courseHandicap);
  }

  public function getCourseHandicapWHS($handicap_index,$slope_rating,$course_rating, $par): int {
    $course_handicap = ($handicap_index * ($slope_rating / 113)) + ($course_rating - $par);
    return (int) round($course_handicap);
  }

  /**
   * Internal: parse the Grint review_score HTML.
   * Extends behavior:
   * - score
   * - putts
   * - penalties_raw + sand_count
   * - fir_code
   */
  protected function parseRoundScoreHtml(string $htmlContent): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $scores = [];

    // Scores
    $scoreQuery = "//table[contains(@class, 'user-input score')]//input[contains(@class, 'input-score-field')]";
    $scoreInputs = $xpath->query($scoreQuery);

    if ($scoreInputs) {
      foreach ($scoreInputs as $input) {
        $holeNumber = (int) $input->getAttribute('data-hole');
        if ($holeNumber < 1 || $holeNumber > 18) {
          continue;
        }
        $scoreValue = $input->getAttribute('data-value');
        $scores[$holeNumber] = [
          'hole' => (string) $holeNumber,
          'score' => $scoreValue,
        ];
      }
    }

    // Putts
    $puttsQuery = "//table[contains(@class, 'user-input optional')]//tr[contains(@class,'input-putts')]//input[contains(@class, 'input-score-field')]";
    $puttsInputs = $xpath->query($puttsQuery);

    if ($puttsInputs) {
      foreach ($puttsInputs as $input) {
        $holeNumber = (int) $input->getAttribute('data-hole');
        if ($holeNumber < 1 || $holeNumber > 18) {
          continue;
        }
        $puttsValue = $input->getAttribute('value');

        if (!isset($scores[$holeNumber])) {
          $scores[$holeNumber] = ['hole' => (string) $holeNumber];
        }
        $scores[$holeNumber]['putts'] = $puttsValue;
      }
    }

    // Penalties + Sand count (FIXED)
    $penQuery = "//table[contains(@class,'user-input optional')]//tr[contains(@class,'input-penalties')]//input[@data-hole]";
    $penInputs = $xpath->query($penQuery);

    if ($penInputs) {
      foreach ($penInputs as $input) {
        $holeNumber = (int) $input->getAttribute('data-hole');
        if ($holeNumber < 1 || $holeNumber > 18) {
          continue;
        }

        $raw = trim((string) $input->getAttribute('value'));

        // FIX: preg_match_all MUST receive the subject string.
        $sandCount = 0;
        if ($raw !== '') {
          $sandCount = preg_match_all('/s/i', $raw, $m) ?: 0;
        }

        if (!isset($scores[$holeNumber])) {
          $scores[$holeNumber] = ['hole' => (string) $holeNumber];
        }

        $scores[$holeNumber]['penalties_raw'] = $raw;
        $scores[$holeNumber]['sand_count'] = $sandCount;
      }
    }

    // FIR / Tee Accuracy
    $firQuery = "//table[contains(@class,'user-input optional')]//tr[contains(@class,'input-facc')]//input[@type='hidden' and starts-with(@name,'fH')]";
    $firInputs = $xpath->query($firQuery);

    if ($firInputs) {
      foreach ($firInputs as $input) {
        $name = (string) $input->getAttribute('name'); // fH4
        $val = trim((string) $input->getAttribute('value'));

        if (!preg_match('/^fH(\d{1,2})$/', $name, $m)) {
          continue;
        }
        $holeNumber = (int) $m[1];
        if ($holeNumber < 1 || $holeNumber > 18) {
          continue;
        }

        if (!isset($scores[$holeNumber])) {
          $scores[$holeNumber] = ['hole' => (string) $holeNumber];
        }
        $scores[$holeNumber]['fir_code'] = $val;
      }
    }

    return $scores;
  }

  public function getRoundScore($roundId = 0) {
    $roundId = (int) $roundId;
    if ($roundId <= 0) {
      return [];
    }

    $uri = '/score/review_score/' . $roundId;
    $htmlContent = $this->getRequest($uri);
    $scores = $this->parseRoundScoreHtml((string) $htmlContent);

    if (!empty($scores)) {
      return $scores;
    }

    $uri9 = '/score/review_score/' . $roundId . '/9';
    $htmlContent9 = $this->getRequest($uri9);
    $scores9 = $this->parseRoundScoreHtml((string) $htmlContent9);

    return $scores9;
  }

  public function getRoundFeed($user_id = 1597150, $wave = NULL) {
    $uri = '/newsfeed_util/loadActivityFriend';
    $payload = [
      'friendId' => $user_id,
    ];

    if ($wave !== NULL && $wave !== '' && is_numeric($wave)) {
      $payload['wave'] = (int) $wave;
    }

    return $this->postRequestHTML($uri, $payload);
  }

  public function processHandicap($handicap_html){
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($handicap_html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $querySectionOut = "//td[contains(@class, 'data-entry') and contains(@class, 'section-out')]";
    $querySectionIn = "//td[contains(@class, 'data-entry') and contains(@class, 'section-in')]";

    $sectionOutValues = $this->extractValues($xpath, $querySectionOut);
    $sectionInValues = $this->extractValues($xpath, $querySectionIn);

    $combinedValues['hole_handicap'] = array_merge($sectionOutValues, $sectionInValues);
    return $combinedValues;
  }

  public function processYardages($yardage_html) {
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($yardage_html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $querySectionOut = "//td[contains(@class, 'data-entry') and contains(@class, 'section-out')]";
    $querySectionIn = "//td[contains(@class, 'data-entry') and contains(@class, 'section-in')]";
    $querySubtotalOut = "//td[contains(@class, 'subtotal') and contains(@class, 'section-out')]";
    $querySubtotalIn = "//td[contains(@class, 'subtotal') and contains(@class, 'section-in')]";
    $queryTotalYardage = "//td[contains(@class, 'total') and contains(@class, 'yardage')]";

    $sectionOutValues = $this->extractValues($xpath, $querySectionOut);
    $sectionInValues = $this->extractValues($xpath, $querySectionIn);
    $subtotalOut = $this->extractSingleValue($xpath, $querySubtotalOut);
    $subtotalIn = $this->extractSingleValue($xpath, $querySubtotalIn);
    $totalYardage = $this->extractSingleValue($xpath, $queryTotalYardage);

    $combinedValues = array_merge($sectionOutValues, $sectionInValues);

    $yardages['hole_yardage'] = $combinedValues;
    $yardages['front_yardage'] = $subtotalOut;
    $yardages['back_yardage'] = $subtotalIn;
    $yardages['total_yardage'] = $totalYardage;

    return $yardages;
  }

  public function processPar($par_html){
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($par_html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $querySectionOut = "//td[contains(@class, 'data-entry') and contains(@class, 'section-out')]";
    $querySectionIn = "//td[contains(@class, 'data-entry') and contains(@class, 'section-in')]";
    $querySubtotalOut = "//td[contains(@class, 'subtotal') and contains(@class, 'section-out')]";
    $querySubtotalIn = "//td[contains(@class, 'subtotal') and contains(@class, 'section-in')]";
    $queryTotalPar = "//td[contains(@class, 'total') and contains(@class, 'course-par')]";

    $sectionOutValues = $this->extractValues($xpath, $querySectionOut);
    $sectionInValues = $this->extractValues($xpath, $querySectionIn);
    $subtotalOut = $this->extractSingleValue($xpath, $querySubtotalOut);
    $subtotalIn = $this->extractSingleValue($xpath, $querySubtotalIn);
    $totalPar = $this->extractSingleValue($xpath, $queryTotalPar);

    $combinedValues = array_merge($sectionOutValues, $sectionInValues);

    $pars['hole_par'] = $combinedValues;
    $pars['front_par'] = $subtotalOut;
    $pars['back_par'] = $subtotalIn;
    $pars['total_par'] = $totalPar;

    return $pars;
  }

  public function extractValues(DOMXPath $xpath, string $query): array {
    $entries = $xpath->query($query);
    $values = [];
    foreach ($entries as $entry) {
      $values[] = trim($entry->textContent);
    }
    return $values;
  }

  function extractSingleValue($xpath, $query) {
    $entry = $xpath->query($query)->item(0);
    return $entry ? trim($entry->textContent) : null;
  }

}
