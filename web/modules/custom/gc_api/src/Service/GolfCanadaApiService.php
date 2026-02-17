<?php

namespace Drupal\gc_api\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\State\StateInterface;

class GolfCanadaApiService {

  protected $httpClient;
  protected $state;
  protected $settings;

  public function __construct(ClientInterface $http_client, StateInterface $state, $settings) {
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->settings = $settings;
  }

  /**
   * Get a valid token, refreshing if expired.
   */
  public function getValidToken() {
    if ($this->isTokenExpired()) {
      return $this->authenticate();
    }

    return $this->state->get('gc_api.token');
  }

  /**
   * Check if the token is expired (default 1 hour).
   */
  protected function isTokenExpired() {
    $timestamp = $this->state->get('gc_api.token_timestamp');
    $expires_in = $this->state->get('gc_api.token_expires_in') ?? 3600;

    return !$timestamp || (time() - $timestamp) >= $expires_in;
  }

  /**
   * Authenticate with the Golf Canada API and store the token.
   */
  public function authenticate() {
    $credentials = $this->settings->get('gc_api.credentials');

    if (!$credentials || empty($credentials['username']) || empty($credentials['password'])) {
      \Drupal::logger('gc_api')->error('GC API credentials not set.');
      return NULL;
    }

    try {
      $response = $this->httpClient->post('https://scg.golfcanada.ca/connect/token', [
        'form_params' => [
          'grant_type' => 'password',
          'username' => $credentials['username'],
          'password' => $credentials['password'],
          'scope' => 'address email offline_access openid phone profile roles',
        ],
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (!empty($data['access_token'])) {
        $this->state->set('gc_api.token', $data['access_token']);
        $this->state->set('gc_api.token_timestamp', time());
        $this->state->set('gc_api.token_expires_in', $data['expires_in'] ?? 3600);

        return $data['access_token'];
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('gc_api')->error('Auth error: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Call the profile endpoint and get the handicap index.
   */
  public function getHandicapIndex($gc_user_id) {
    if (empty($gc_user_id)) {
      \Drupal::logger('gc_api')->warning('No Golf Canada user ID provided.');
      return NULL;
    }

    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('No valid token for fetching handicap index.');
      return NULL;
    }

    $url = 'https://scg.golfcanada.ca/api/scores/getProfile?individualId=' . urlencode($gc_user_id);

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (!empty($data['handicap']) && is_numeric($data['handicap'])) {
        return (float) $data['handicap'];
      }

      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('gc_api')->error('Handicap fetch error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  public function getHistory($gc_user_id, $skip = 0, $top = 20) {
    if (empty($gc_user_id) || !is_numeric($gc_user_id)) {
      \Drupal::logger('gc_api')->warning('Invalid GC user ID provided to getHistory(). = ' . $gc_user_id);
      return NULL;
    }

    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('No valid token for fetching score history.');
      return NULL;
    }

    $query = http_build_query([
      '$skip' => $skip,
      '$top' => $top,
      'individualId' => $gc_user_id,
    ]);

    $url = "https://scg.golfcanada.ca/api/scores/getHistory?$query";

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode($response->getBody(), TRUE);
      return $data['data'] ?? [];
    }
    catch (\Exception $e) {
      \Drupal::logger('gc_api')->error('Score history fetch error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  public function getRoundScore($scoreId = 0) {
    if ($scoreId <= 0) {
      \Drupal::logger('gc_api')->warning('Invalid score ID passed to getRoundScore().');
      return [];
    }

    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('Unable to fetch valid token for getRoundScore().');
      return [];
    }

    $url = 'https://scg.golfcanada.ca/api/scores/getScoreDetails?scoreId=' . $scoreId;

    try {
      $client = \Drupal::httpClient();
      $response = $client->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (!isset($data['holeScores']) || !is_array($data['holeScores'])) {
        \Drupal::logger('gc_api')->warning('No hole scores found in GC response.');
        return [];
      }

      $scores = [];
      foreach ($data['holeScores'] as $holeData) {
        $holeRaw = $holeData['holeNumber'];

        if (is_numeric($holeRaw) && floor($holeRaw) == $holeRaw && $holeRaw >= 1 && $holeRaw <= 18) {
          $hole = (int) $holeRaw;

          $scores[$hole] = [
            'hole' => $hole,
            'score' => $holeData['gross'] ?? '',
            'putts' => $holeData['putts'] ?? '',
          ];
        }
      }

      return $scores;
    }
    catch (\Exception $e) {
      \Drupal::logger('gc_api')->error('Error fetching score details: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }
  }

  public function getCourseHandicap($user_id, $course_id, $sub_course_id, $tee_color) {
    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('Unable to fetch valid token for getCourseHandicap().');
      return 0;
    }

    $url = 'https://scg.golfcanada.ca/api/courses/getCourseHandicapInfo?facilityId=' . $course_id . '&handicapPercent=100&individualId=' . $user_id;

    try {
      $client = \Drupal::httpClient();
      $response = $client->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        \Drupal::logger('gc_api')->error('Invalid JSON response from GC API: @error', [
          '@error' => json_last_error_msg(),
        ]);
        return 0;
      }

      if (!isset($data['facility']['courses']) || !is_array($data['facility']['courses'])) {
        \Drupal::logger('gc_api')->error('Missing or invalid "courses" data in GC API response.');
        return 0;
      }

      foreach ($data['facility']['courses'] as $course) {
        if ((int) $course['id'] === (int) $sub_course_id && isset($course['tees']) && is_array($course['tees'])) {
          foreach ($course['tees'] as $tee) {
            if (isset($tee['name']) && stripos($tee['name'], $tee_color) !== false) {
              return $tee['playingHandicap'] ?? 0;
            }
          }
          return 0;
        }
      }

      return 0;
    }
    catch (\Exception $e) {
      \Drupal::logger('gc_api')->error('Error fetching course handicap data: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Search facilities (clubs).
   */
  public function searchFacilities(string $text, int $top = 10, string $nationalAssociation = 'RCGA'): array {
    $text = trim($text);
    if ($text === '') {
      return [];
    }

    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('No valid token for searchFacilities().');
      return [];
    }

    $url = 'https://scg.golfcanada.ca/api/facilities/search?' . http_build_query([
        '$top' => $top,
        'nationalAssociation' => $nationalAssociation,
        'text' => $text,
      ]);

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      $facilities = $data['facilities'] ?? [];

      if (!is_array($facilities)) {
        return [];
      }

      $out = [];
      foreach ($facilities as $f) {
        if (!empty($f['id']) && !empty($f['name'])) {
          $out[] = [
            'id' => (int) $f['id'],
            'name' => (string) $f['name'],
            'city' => (string) ($f['city'] ?? ''),
            'region' => (string) ($f['region'] ?? ''),
          ];
        }
      }

      return $out;
    }
    catch (\Throwable $e) {
      \Drupal::logger('gc_api')->notice('searchFacilities() failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Get courses for a facility + memberId (GC id).
   *
   * IMPORTANT: This preserves tee hole data from GC:
   * tee['holes'][] includes number/par/yards/handicap (plus other fields).
   */
  public function getCourses(int $facilityId, int $memberId): array {
    if ($facilityId <= 0 || $memberId <= 0) {
      return [];
    }

    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('No valid token for getCourses().');
      return [];
    }

    $url = 'https://scg.golfcanada.ca/api/courses/getCourses?' . http_build_query([
        'facilityId' => $facilityId,
        'memberId' => $memberId,
      ]);

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'verify' => FALSE, // dev only
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data)) {
        return [];
      }

      $out = [];
      foreach ($data as $course) {
        if (empty($course['id']) || empty($course['name'])) {
          continue;
        }

        $teesOut = [];
        if (!empty($course['tees']) && is_array($course['tees'])) {
          foreach ($course['tees'] as $tee) {
            if (empty($tee['id']) || empty($tee['name'])) {
              continue;
            }

            $holes = [];
            if (!empty($tee['holes']) && is_array($tee['holes'])) {
              $holes = $tee['holes'];
            }

            $teesOut[] = [
              'id' => (int) $tee['id'],
              'name' => (string) $tee['name'],
              'holes' => $holes,
              'frontRating' => $tee['frontRating'] ?? NULL,
              'backRating' => $tee['backRating'] ?? NULL,
              'rating' => $tee['rating'] ?? NULL,
              'frontSlope' => $tee['frontSlope'] ?? NULL,
              'backSlope' => $tee['backSlope'] ?? NULL,
              'slope' => $tee['slope'] ?? NULL,
            ];
          }
        }

        $out[] = [
          'id' => (int) $course['id'],
          'name' => (string) $course['name'],
          'courseStatus' => (string) ($course['courseStatus'] ?? ''),
          'tees' => $teesOut,
        ];
      }

      return $out;
    }
    catch (\Throwable $e) {
      \Drupal::logger('gc_api')->notice('getCourses() failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Helper: Return hole map (1..18) for a specific facility/course/tee selection.
   */
  public function getTeeHoles(int $facilityId, int $memberId, int $courseId, int $teeId): array {
    $courses = $this->getCourses($facilityId, $memberId);
    if (empty($courses)) {
      return [];
    }

    $holeMap = [];

    foreach ($courses as $c) {
      if ((int) ($c['id'] ?? 0) !== $courseId) {
        continue;
      }

      $tees = $c['tees'] ?? [];
      if (!is_array($tees)) {
        return [];
      }

      foreach ($tees as $t) {
        if ((int) ($t['id'] ?? 0) !== $teeId) {
          continue;
        }

        $holes = $t['holes'] ?? [];
        if (!is_array($holes)) {
          return [];
        }

        foreach ($holes as $h) {
          $numRaw = $h['number'] ?? NULL;

          // Only keep true integer holes 1..18 (filters 9.1, 18.2 etc)
          if (is_numeric($numRaw) && floor((float) $numRaw) == (float) $numRaw) {
            $num = (int) $numRaw;
            if ($num >= 1 && $num <= 18) {
              $holeMap[$num] = [
                'yards' => isset($h['yards']) && is_numeric($h['yards']) ? (int) $h['yards'] : NULL,
                'par' => isset($h['par']) && is_numeric($h['par']) ? (int) $h['par'] : NULL,
                'handicap' => isset($h['handicap']) && is_numeric($h['handicap']) ? (int) $h['handicap'] : NULL,
              ];
            }
          }
        }

        ksort($holeMap);
        return $holeMap;
      }

      return [];
    }

    return [];
  }

  /**
   * POST a score payload to Golf Canada.
   *
   * Default endpoint used here:
   *   https://scg.golfcanada.ca/api/scores/postScore
   *
   * You can override by setting:
   *   $settings['gc_api']['endpoints']['post_score']
   */
  public function postScore(array $payload): array {
    $token = $this->getValidToken();
    if (!$token) {
      \Drupal::logger('gc_api')->error('No valid token for postScore().');
      return [
        'ok' => FALSE,
        'status' => 0,
        'error' => 'No valid token',
        'response' => NULL,
      ];
    }

    $endpoint = 'https://scg.golfcanada.ca/api/scores/postScore';
    try {
      $custom = $this->settings->get('gc_api.endpoints');
      if (is_array($custom) && !empty($custom['post_score'])) {
        $endpoint = (string) $custom['post_score'];
      }
      // Also support nested style if you prefer:
      // $settings['gc_api']['endpoints']['post_score']
      $custom2 = $this->settings->get('gc_api');
      if (is_array($custom2) && !empty($custom2['endpoints']['post_score'])) {
        $endpoint = (string) $custom2['endpoints']['post_score'];
      }
    }
    catch (\Throwable $e) {
      // Ignore; keep default endpoint.
    }

    try {
      $resp = $this->httpClient->post($endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'verify' => FALSE, // dev only
      ]);

      $status = (int) $resp->getStatusCode();
      $body = (string) $resp->getBody();
      $decoded = json_decode($body, TRUE);

      \Drupal::logger('gc_api')->notice('postScore() status=@s endpoint=@u', [
        '@s' => $status,
        '@u' => $endpoint,
      ]);

      return [
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'error' => NULL,
        'response' => $decoded !== NULL ? $decoded : $body,
      ];
    }
    catch (\Throwable $e) {
      \Drupal::logger('gc_api')->error('postScore() failed: @msg endpoint=@u', [
        '@msg' => $e->getMessage(),
        '@u' => $endpoint,
      ]);

      return [
        'ok' => FALSE,
        'status' => 0,
        'error' => $e->getMessage(),
        'response' => NULL,
      ];
    }
  }

  /**
   * Return hole arrays (1..18) for a specific facility/course/tee selection.
   * Uses getTeeHoles() as the source of truth.
   *
   * Output:
   * [
   *   'pars' => [0..17],
   *   'yards' => [0..17],
   *   'hdcp' => [0..17],
   *   'par_out' => int|string,
   *   'par_in' => int|string,
   *   'par_total' => int|string,
   *   'yards_out' => int|string,
   *   'yards_in' => int|string,
   *   'yards_total' => int|string,
   * ]
   */
  public function getTeeHoleArrays(int $facilityId, int $memberId, int $courseId, int $teeId): array {
    $holeMap = $this->getTeeHoles($facilityId, $memberId, $courseId, $teeId);
    if (empty($holeMap)) {
      return [];
    }

    $pars = array_fill(0, 18, '');
    $yards = array_fill(0, 18, '');
    $hdcp = array_fill(0, 18, '');

    $parOut = 0; $parIn = 0; $parTotal = 0;
    $yardsOut = 0; $yardsIn = 0; $yardsTotal = 0;

    for ($h = 1; $h <= 18; $h++) {
      $p = $holeMap[$h]['par'] ?? NULL;
      $y = $holeMap[$h]['yards'] ?? NULL;
      $hc = $holeMap[$h]['handicap'] ?? NULL;

      $pars[$h - 1] = $p ?? '';
      $yards[$h - 1] = $y ?? '';
      $hdcp[$h - 1] = $hc ?? '';

      if (is_numeric($p)) {
        $parTotal += (int) $p;
        if ($h <= 9) { $parOut += (int) $p; } else { $parIn += (int) $p; }
      }
      if (is_numeric($y)) {
        $yardsTotal += (int) $y;
        if ($h <= 9) { $yardsOut += (int) $y; } else { $yardsIn += (int) $y; }
      }
    }

    return [
      'pars' => $pars,
      'yards' => $yards,
      'hdcp' => $hdcp,
      'par_out' => $parOut ?: '',
      'par_in' => $parIn ?: '',
      'par_total' => $parTotal ?: '',
      'yards_out' => $yardsOut ?: '',
      'yards_in' => $yardsIn ?: '',
      'yards_total' => $yardsTotal ?: '',
    ];
  }

  /**
   * OPTIONAL quality-of-life:
   * Try to match a GC course + tee from human-readable strings (ex: Grint names).
   * You can use this later to auto-select course/tee.
   *
   * Returns:
   * ['courseId'=>int, 'teeId'=>int, 'courseName'=>string, 'teeName'=>string] or []
   */
  public function matchCourseAndTee(int $facilityId, int $memberId, string $courseName, string $teeNameOrColor): array {
    $courseName = $this->normalizeName($courseName);
    $teeNameOrColor = $this->normalizeName($teeNameOrColor);

    if ($facilityId <= 0 || $memberId <= 0 || $courseName === '') {
      return [];
    }

    $courses = $this->getCourses($facilityId, $memberId);
    if (empty($courses)) {
      return [];
    }

    // 1) Find best-matching course (contains match).
    $bestCourse = NULL;
    foreach ($courses as $c) {
      $n = $this->normalizeName((string) ($c['name'] ?? ''));
      if ($n !== '' && ($n === $courseName || str_contains($n, $courseName) || str_contains($courseName, $n))) {
        $bestCourse = $c;
        break;
      }
    }
    if (!$bestCourse) {
      // fallback: first "contains" using partial tokens
      foreach ($courses as $c) {
        $n = $this->normalizeName((string) ($c['name'] ?? ''));
        if ($n !== '' && $courseName !== '' && str_contains($courseName, $n)) {
          $bestCourse = $c;
          break;
        }
      }
    }

    if (!$bestCourse) {
      return [];
    }

    $courseId = (int) ($bestCourse['id'] ?? 0);
    $courseLabel = (string) ($bestCourse['name'] ?? '');

    // 2) Find best-matching tee (contains match).
    $bestTee = NULL;
    $tees = $bestCourse['tees'] ?? [];
    if (is_array($tees) && $teeNameOrColor !== '') {
      foreach ($tees as $t) {
        $tn = $this->normalizeName((string) ($t['name'] ?? ''));
        if ($tn !== '' && (str_contains($tn, $teeNameOrColor) || str_contains($teeNameOrColor, $tn))) {
          $bestTee = $t;
          break;
        }
      }
    }

    if (!$bestTee) {
      return []; // don’t guess wrong
    }

    return [
      'courseId' => $courseId,
      'teeId' => (int) ($bestTee['id'] ?? 0),
      'courseName' => $courseLabel,
      'teeName' => (string) ($bestTee['name'] ?? ''),
    ];
  }

  protected function normalizeName(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/[^a-z0-9 ]/', '', $s);
    return trim((string) $s);
  }

}
