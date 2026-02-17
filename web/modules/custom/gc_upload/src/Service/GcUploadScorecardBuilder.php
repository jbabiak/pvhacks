<?php

namespace Drupal\gc_upload\Service;

use DOMDocument;
use DOMXPath;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\grint_api\Grint_API_Service;

class GcUploadScorecardBuilder {

  protected Grint_API_Service $grintAPI;
  protected $logger;

  public function __construct(Grint_API_Service $grintAPI, LoggerChannelFactoryInterface $loggerFactory) {
    $this->grintAPI = $grintAPI;
    $this->logger = $loggerFactory->get('gc_upload');
  }

  protected function log(string $message, array $context = []): void {
    $this->logger->notice($message, $context);
  }

  public function extractRoundMeta(int $roundId): array {
    $uri = '/score/review_score/' . $roundId;
    $html = $this->grintAPI->getRequest($uri);

    $this->log('Fetched review_score HTML length=@len for roundId=@rid', [
      '@len' => strlen((string) $html),
      '@rid' => (string) $roundId,
    ]);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $meta = [
      'course_id' => '',
      'tee_color' => '',
      'course_name' => '',
    ];

    $meta['course_id'] = $this->firstXPathValue($xpath, [
      "//input[@name='course_id']/@value",
      "//input[@id='course_id']/@value",
      "//input[contains(@name,'course') and contains(@name,'id')]/@value",
    ]);

    $meta['tee_color'] = $this->firstXPathValue($xpath, [
      "//input[@name='tee']/@value",
      "//input[@id='tee']/@value",
      "//select[@name='tee']/option[@selected]/@value",
      "//select[@id='tee']/option[@selected]/@value",
    ]);

    $meta['course_name'] = $this->firstXPathText($xpath, [
      "//*[contains(@class,'course-name')][1]",
      "//h1[1]",
      "//h2[1]",
      "//*[@id='course_name'][1]",
      "//*[@id='courseName'][1]",
    ]);

    if ($meta['course_id'] === '' && $meta['course_name'] !== '') {
      $cid = $this->grintAPI->getCourseIdFromString($meta['course_name']);
      if (!empty($cid)) {
        $meta['course_id'] = (string) $cid;
      }
    }

    $this->log('Round meta raw: course_id=@cid tee=@tee course_name=@name', [
      '@cid' => $meta['course_id'],
      '@tee' => $meta['tee_color'],
      '@name' => $meta['course_name'],
    ]);

    return $meta;
  }

  protected function firstXPathValue(DOMXPath $xpath, array $queries): string {
    foreach ($queries as $q) {
      $node = $xpath->query($q)->item(0);
      if ($node && trim($node->nodeValue) !== '') {
        return trim($node->nodeValue);
      }
    }
    return '';
  }

  protected function firstXPathText(DOMXPath $xpath, array $queries): string {
    foreach ($queries as $q) {
      $node = $xpath->query($q)->item(0);
      if ($node) {
        $text = trim($node->textContent);
        if ($text !== '') {
          return $text;
        }
      }
    }
    return '';
  }

  protected function firCodeToEnum(?string $code): string {
    $code = trim((string) $code);
    return match ($code) {
      '1' => 'MissedLeft',
      '2' => 'MissedRight',
      '3' => 'Hit',
      '4' => 'MissedShort',
      '6' => 'MissedLong',
      default => '',
    };
  }

  protected function penaltiesRawToCount(?string $raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') {
      return '';
    }

    $raw2 = preg_replace('/s/i', '', $raw);
    $raw2 = strtoupper(trim((string) $raw2));
    if ($raw2 === '') {
      return '';
    }

    if (preg_match_all('/[A-Z]/', $raw2, $m)) {
      $n = count($m[0]);
      return $n > 0 ? (string) $n : '';
    }

    return '';
  }

  protected function sandCountFromRaw(?string $raw): int {
    $raw = strtoupper(trim((string) $raw));
    if ($raw === '') {
      return 0;
    }
    return substr_count($raw, 'S');
  }

  protected function toNullableInt($v): ?int {
    if ($v === NULL) {
      return NULL;
    }
    $s = trim((string) $v);
    if ($s === '' || !is_numeric($s)) {
      return NULL;
    }
    return (int) $s;
  }

  protected function inferUpDown($par, $gross, $putts): bool {
    $par_i = $this->toNullableInt($par);
    $gross_i = $this->toNullableInt($gross);
    $putts_i = $this->toNullableInt($putts);

    if ($par_i === NULL || $gross_i === NULL || $putts_i === NULL) {
      return FALSE;
    }

    return ($putts_i === 1) && ($gross_i === $par_i);
  }

  protected function findGcCourseTeeNames(array $coursesPayload, int $courseId, int $teeId): array {
    $out = ['course' => '', 'tee' => ''];
    foreach ($coursesPayload as $c) {
      if ((int) ($c['id'] ?? 0) !== $courseId) {
        continue;
      }
      $out['course'] = (string) ($c['name'] ?? '');
      $tees = $c['tees'] ?? [];
      if (is_array($tees)) {
        foreach ($tees as $t) {
          if ((int) ($t['id'] ?? 0) === $teeId) {
            $out['tee'] = (string) ($t['name'] ?? '');
            break;
          }
        }
      }
      break;
    }
    return $out;
  }

  /**
   * Same "played hole" heuristic as the form (so builder can self-heal if holes_mode is wrong).
   */
  protected function holeLooksPlayed(array $holeData): bool {
    if (isset($holeData['score']) && is_numeric($holeData['score']) && (int) $holeData['score'] > 0) {
      return TRUE;
    }
    if (isset($holeData['putts']) && is_numeric($holeData['putts']) && (int) $holeData['putts'] > 0) {
      return TRUE;
    }

    $signals = [
      'fir_code',
      'penalties_raw',
      'pen_raw',
      'penalties',
      'sand_count',
    ];

    foreach ($signals as $k) {
      if (!empty($holeData[$k]) && trim((string) $holeData[$k]) !== '' && trim((string) $holeData[$k]) !== '0') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * If holes_mode comes in as 18 but only 9 holes look played,
   * infer front9/back9 from the distribution.
   */
  protected function inferHolesModeFromScores(array $scores): string {
    $played = [];
    for ($h = 1; $h <= 18; $h++) {
      if (!isset($scores[$h]) || !is_array($scores[$h])) {
        continue;
      }
      if ($this->holeLooksPlayed($scores[$h])) {
        $played[] = $h;
      }
    }

    $this->log('inferHolesModeFromScores(): played holes=@holes count=@c', [
      '@holes' => implode(',', $played),
      '@c' => count($played),
    ]);

    if (count($played) === 0) {
      return '18';
    }
    if (count($played) > 11) {
      return '18';
    }

    $front = 0;
    $back = 0;
    foreach ($played as $h) {
      if ($h <= 9) {
        $front++;
      }
      else {
        $back++;
      }
    }

    if ($front > 0 && $back === 0) {
      return 'front9';
    }
    if ($back > 0 && $front === 0) {
      return 'back9';
    }

    if (count($played) <= 10) {
      return ($front >= $back) ? 'front9' : 'back9';
    }

    return '18';
  }

  protected function holesListFromMode(string $holes_mode): array {
    $holes_mode = trim($holes_mode);
    return match ($holes_mode) {
      'front9' => range(1, 9),
      'back9' => range(10, 18),
      default => range(1, 18),
    };
  }

  protected function calculateTotals(array $scores, array $pars, array $hole_list): array {
    $gross = 0;
    $putts = 0;
    $par = 0;

    foreach ($hole_list as $h) {
      $hs = $scores[$h]['score'] ?? NULL;
      if (is_numeric($hs)) {
        $gross += (int) $hs;
      }

      $hp = $scores[$h]['putts'] ?? NULL;
      if (is_numeric($hp)) {
        $putts += (int) $hp;
      }

      $p = $pars[$h - 1] ?? NULL;
      if (is_numeric($p)) {
        $par += (int) $p;
      }
    }

    return [
      'gross' => $gross,
      'putts' => $putts,
      'par' => $par,
    ];
  }

  public function buildScorecard(array $scores, array $meta = [], string $grint_user_id = ''): array {
    $grint_course_name = trim((string) ($meta['course_name'] ?? ''));
    $grint_tee_color = trim((string) ($meta['tee_color'] ?? ''));

    $holes_mode = (string) ($meta['holes_mode'] ?? '18');
    if (!in_array($holes_mode, ['18', 'front9', 'back9'], TRUE)) {
      $holes_mode = '18';
    }

    // NEW: self-heal if holes_mode is 18 but only 9 holes actually played.
    if ($holes_mode === '18') {
      $inferred = $this->inferHolesModeFromScores($scores);
      if (in_array($inferred, ['front9', 'back9'], TRUE)) {
        $this->log('Holes mode overridden by inference: from=18 to=@m', ['@m' => $inferred]);
        $holes_mode = $inferred;
      }
    }

    $hole_list = $this->holesListFromMode($holes_mode);

    $gc_member_id = (int) ($meta['gc_id'] ?? 0);
    $gc_facility_id = (int) ($meta['gc_facility_id'] ?? 0);
    $gc_course_id = (int) ($meta['gc_course_id'] ?? 0);
    $gc_tee_id = (int) ($meta['gc_tee_id'] ?? 0);

    $pars = array_fill(0, 18, '');
    $yards = array_fill(0, 18, '');
    $hdcp = array_fill(0, 18, '');

    $par_out = '';
    $par_in = '';
    $par_total = '';

    $yards_out = '';
    $yards_in = '';
    $yards_total = '';

    $display_course_name = $grint_course_name;
    $display_tee_name = $grint_tee_color;

    $course_handicap = NULL;

    $courses_payload = [];
    if ($gc_member_id > 0 && $gc_facility_id > 0) {
      try {
        /** @var \Drupal\gc_api\Service\GolfCanadaApiService $gc */
        $gc = \Drupal::service('gc_api.golf_canada_api_service');
        $courses_payload = $gc->getCourses($gc_facility_id, $gc_member_id);
      }
      catch (\Throwable $e) {
        $this->log('GC getCourses failed (non-fatal): @msg', ['@msg' => $e->getMessage()]);
      }
    }

    if ($gc_member_id > 0 && $gc_facility_id > 0 && $gc_course_id > 0 && $gc_tee_id > 0) {
      try {
        /** @var \Drupal\gc_api\Service\GolfCanadaApiService $gc */
        $gc = \Drupal::service('gc_api.golf_canada_api_service');

        if (!empty($courses_payload)) {
          $names = $this->findGcCourseTeeNames($courses_payload, $gc_course_id, $gc_tee_id);
          if ($names['course'] !== '') {
            $display_course_name = $names['course'];
          }
          if ($names['tee'] !== '') {
            $display_tee_name = $names['tee'];
          }
        }

        $holeArrays = $gc->getTeeHoleArrays($gc_facility_id, $gc_member_id, $gc_course_id, $gc_tee_id);
        if (!empty($holeArrays)) {
          $pars = $holeArrays['pars'] ?? $pars;
          $yards = $holeArrays['yards'] ?? $yards;
          $hdcp = $holeArrays['hdcp'] ?? $hdcp;

          $par_out = $holeArrays['par_out'] ?? $par_out;
          $par_in = $holeArrays['par_in'] ?? $par_in;
          $par_total = $holeArrays['par_total'] ?? $par_total;

          $yards_out = $holeArrays['yards_out'] ?? $yards_out;
          $yards_in = $holeArrays['yards_in'] ?? $yards_in;
          $yards_total = $holeArrays['yards_total'] ?? $yards_total;
        }

        $teeNameForMatch = $display_tee_name !== '' ? $display_tee_name : $grint_tee_color;
        $ch = $gc->getCourseHandicap($gc_member_id, $gc_facility_id, $gc_course_id, $teeNameForMatch);
        if (is_numeric($ch)) {
          $course_handicap = (int) $ch;
        }
      }
      catch (\Throwable $e) {
        $this->log('GC hole load / handicap failed: @msg', ['@msg' => $e->getMessage()]);
      }
    }
    else {
      $this->log('GC ids missing; cannot load tee holes. member=@m facility=@f course=@c tee=@t', [
        '@m' => $gc_member_id,
        '@f' => $gc_facility_id,
        '@c' => $gc_course_id,
        '@t' => $gc_tee_id,
      ]);
    }

    $is9 = in_array($holes_mode, ['front9', 'back9'], TRUE);
    if ($is9) {
      $par_calc = 0;
      $yards_calc = 0;

      foreach ($hole_list as $h) {
        $p = $pars[$h - 1] ?? NULL;
        if (is_numeric($p)) {
          $par_calc += (int) $p;
        }

        $y = $yards[$h - 1] ?? NULL;
        if (is_numeric($y)) {
          $yards_calc += (int) $y;
        }
      }

      $par_total = (string) $par_calc;
      $yards_total = (string) $yards_calc;

      if ($holes_mode === 'front9') {
        $par_out = $par_total;
        $yards_out = $yards_total;
        $par_in = '';
        $yards_in = '';
      }
      else {
        $par_in = $par_total;
        $yards_in = $yards_total;
        $par_out = '';
        $yards_out = '';
      }
    }

    $fir_options = [
      '' => '-',
      'Hit' => '◎',
      'MissedRight' => '▶',
      'MissedLeft' => '◀',
      'MissedShort' => '▼',
      'MissedLong' => '▲',
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['gc-upload-scorecard']],
      '#attached' => [
        'library' => [
          'hacks_forms/scorecard-helper',
          'gc_upload/scorecard',
        ],
      ],
    ];

    $build['scores_info'] = [
      '#type' => 'markup',
      '#markup' =>
        '<div class="gc-upload-scores-info">'
        . '<span>Course: <span id="user_course_name">' . htmlspecialchars($display_course_name ?: '(unknown)', ENT_QUOTES, 'UTF-8') . '</span></span>'
        . '<span style="margin-left:10px;">Tee: <span id="user_course_tee_color">' . htmlspecialchars($display_tee_name ?: '(unknown)', ENT_QUOTES, 'UTF-8') . '</span></span>'
        . '<span style="margin-left:10px;">Course Handicap: <span id="user_course_handicap">' . htmlspecialchars($course_handicap !== NULL ? (string) $course_handicap : '(unknown)', ENT_QUOTES, 'UTF-8') . '</span></span>'
        . '</div>',
    ];

    // FRONT TABLE
    $show_front = ($holes_mode !== 'back9');
    if ($show_front) {
      $build['scores_table']['front'] = [
        '#type' => 'table',
        '#header' => ['Hole', '1','2','3','4','5','6','7','8','9','Out'],
        '#attributes' => ['class' => ['scorecard-table-input']],
      ];

      $build['scores_table']['front']['yards'][0] = ['#markup' => 'Yards'];
      $build['scores_table']['front']['hdcp'][0] = ['#markup' => 'Hdcp'];
      $build['scores_table']['front']['par'][0] = ['#markup' => 'Par'];
      $build['scores_table']['front']['score'][0] = ['#markup' => 'Score'];
      $build['scores_table']['front']['putts'][0] = ['#markup' => 'Putts'];
      $build['scores_table']['front']['fir'][0] = ['#markup' => 'FIR'];
      $build['scores_table']['front']['penalty'][0] = ['#markup' => 'Pen'];
      $build['scores_table']['front']['updown'][0] = ['#markup' => 'U/D'];
      $build['scores_table']['front']['sandsave'][0] = ['#markup' => 'Sand'];

      for ($i = 1; $i <= 9; $i++) {
        $hole = $i;
        if (!in_array($hole, $hole_list, TRUE)) {
          continue;
        }

        $hole_score = isset($scores[$hole]['score']) ? (string) $scores[$hole]['score'] : '';
        $hole_putts = isset($scores[$hole]['putts']) ? (string) $scores[$hole]['putts'] : '';

        $pen_raw = (string) ($scores[$hole]['penalties_raw'] ?? $scores[$hole]['pen_raw'] ?? $scores[$hole]['penalties'] ?? '');
        $sand_from_raw = $this->sandCountFromRaw($pen_raw);

        $sand_count = 0;
        if (isset($scores[$hole]['sand_count']) && is_numeric($scores[$hole]['sand_count'])) {
          $sand_count = (int) $scores[$hole]['sand_count'];
        }
        $sand_count = max($sand_count, $sand_from_raw);

        $fir_code = isset($scores[$hole]['fir_code']) ? (string) $scores[$hole]['fir_code'] : '';

        $par = $pars[$hole - 1] ?? '';
        $default_fir = $this->firCodeToEnum($fir_code);
        $default_pen = $this->penaltiesRawToCount($pen_raw);

        $infer_updown = $this->inferUpDown($par, $hole_score, $hole_putts);
        $infer_sand = ($sand_count > 0);

        $this->log('UD hole @h: par=@par gross=@g putts=@p => ud=@ud', [
          '@h' => (string) $hole,
          '@par' => (string) $par,
          '@g' => (string) $hole_score,
          '@p' => (string) $hole_putts,
          '@ud' => $infer_updown ? '1' : '0',
        ]);

        $build['scores_table']['front']['yards'][$i] = ['#markup' => $yards[$hole - 1] ?? ''];
        $build['scores_table']['front']['hdcp'][$i] = ['#markup' => $hdcp[$hole - 1] ?? ''];
        $build['scores_table']['front']['par'][$i] = ['#markup' => $par];

        $build['scores_table']['front']['score'][$i] = [
          '#type' => 'textfield',
          '#size' => 1,
          '#required' => FALSE,
          '#attributes' => [
            'pattern' => '[0-9]*',
            'min' => '0',
            'class' => ['gc-upload-score-input'],
            'data-hole' => (string) $hole,
          ],
          '#default_value' => $hole_score,
        ];

        $build['scores_table']['front']['putts'][$i] = [
          '#type' => 'textfield',
          '#size' => 1,
          '#required' => FALSE,
          '#attributes' => [
            'pattern' => '[0-9]*',
            'min' => '0',
            'class' => ['gc-upload-putts-input'],
            'data-hole' => (string) $hole,
          ],
          '#default_value' => $hole_putts,
        ];

        if (is_numeric($par) && (int) $par === 3) {
          $build['scores_table']['front']['fir'][$i] = ['#markup' => '—'];
        }
        else {
          $build['scores_table']['front']['fir'][$i] = [
            '#type' => 'select',
            '#options' => $fir_options,
            '#default_value' => $default_fir !== '' ? $default_fir : '',
            '#attributes' => [
              'class' => ['gc-upload-fir-input'],
              'data-hole' => (string) $hole,
            ],
          ];
        }

        $build['scores_table']['front']['penalty'][$i] = [
          '#type' => 'textfield',
          '#size' => 1,
          '#required' => FALSE,
          '#attributes' => [
            'pattern' => '[0-9]*',
            'min' => '0',
            'class' => ['gc-upload-penalty-input'],
            'data-hole' => (string) $hole,
          ],
          '#default_value' => $default_pen,
        ];

        $build['scores_table']['front']['updown'][$i] = [
          '#type' => 'checkbox',
          '#default_value' => $infer_updown ? 1 : 0,
          '#attributes' => [
            'class' => ['gc-upload-updown-input'],
            'data-hole' => (string) $hole,
          ],
        ];

        $build['scores_table']['front']['sandsave'][$i] = [
          '#type' => 'checkbox',
          '#default_value' => $infer_sand ? 1 : 0,
          '#attributes' => [
            'class' => ['gc-upload-sandsave-input'],
            'data-hole' => (string) $hole,
          ],
        ];
      }

      $build['scores_table']['front']['yards'][10] = ['#markup' => $yards_out];
      $build['scores_table']['front']['hdcp'][10] = ['#markup' => ''];
      $build['scores_table']['front']['par'][10] = ['#markup' => $par_out];
      $build['scores_table']['front']['score'][10] = ['#markup' => '<span id="scores_table_front_score">0</span>'];
      $build['scores_table']['front']['putts'][10] = ['#markup' => '<span id="scores_table_front_putts">0</span>'];
      $build['scores_table']['front']['fir'][10] = ['#markup' => ''];
      $build['scores_table']['front']['penalty'][10] = ['#markup' => ''];
      $build['scores_table']['front']['updown'][10] = ['#markup' => ''];
      $build['scores_table']['front']['sandsave'][10] = ['#markup' => ''];
    }

    // BACK TABLE
    $show_back = ($holes_mode !== 'front9');
    if ($show_back) {
      $build['scores_table']['back'] = [
        '#type' => 'table',
        '#header' => ['Hole', '10','11','12','13','14','15','16','17','18','In'],
        '#attributes' => ['class' => ['scorecard-table-input']],
      ];

      $build['scores_table']['back']['yards'][0] = ['#markup' => 'Yards'];
      $build['scores_table']['back']['hdcp'][0] = ['#markup' => 'Hdcp'];
      $build['scores_table']['back']['par'][0] = ['#markup' => 'Par'];
      $build['scores_table']['back']['score'][0] = ['#markup' => 'Score'];
      $build['scores_table']['back']['putts'][0] = ['#markup' => 'Putts'];
      $build['scores_table']['back']['fir'][0] = ['#markup' => 'FIR'];
      $build['scores_table']['back']['penalty'][0] = ['#markup' => 'Pen'];
      $build['scores_table']['back']['updown'][0] = ['#markup' => 'U/D'];
      $build['scores_table']['back']['sandsave'][0] = ['#markup' => 'Sand'];

      for ($i = 1; $i <= 9; $i++) {
        $hole = $i + 9;
        if (!in_array($hole, $hole_list, TRUE)) {
          continue;
        }

        $hole_score = isset($scores[$hole]['score']) ? (string) $scores[$hole]['score'] : '';
        $hole_putts = isset($scores[$hole]['putts']) ? (string) $scores[$hole]['putts'] : '';

        $pen_raw = (string) ($scores[$hole]['penalties_raw'] ?? $scores[$hole]['pen_raw'] ?? $scores[$hole]['penalties'] ?? '');
        $sand_from_raw = $this->sandCountFromRaw($pen_raw);

        $sand_count = 0;
        if (isset($scores[$hole]['sand_count']) && is_numeric($scores[$hole]['sand_count'])) {
          $sand_count = (int) $scores[$hole]['sand_count'];
        }
        $sand_count = max($sand_count, $sand_from_raw);

        $fir_code = isset($scores[$hole]['fir_code']) ? (string) $scores[$hole]['fir_code'] : '';

        $par = $pars[$hole - 1] ?? '';
        $default_fir = $this->firCodeToEnum($fir_code);
        $default_pen = $this->penaltiesRawToCount($pen_raw);

        $infer_updown = $this->inferUpDown($par, $hole_score, $hole_putts);
        $infer_sand = ($sand_count > 0);

        $this->log('UD hole @h: par=@par gross=@g putts=@p => ud=@ud', [
          '@h' => (string) $hole,
          '@par' => (string) $par,
          '@g' => (string) $hole_score,
          '@p' => (string) $hole_putts,
          '@ud' => $infer_updown ? '1' : '0',
        ]);

        $build['scores_table']['back']['yards'][$i] = ['#markup' => $yards[$hole - 1] ?? ''];
        $build['scores_table']['back']['hdcp'][$i] = ['#markup' => $hdcp[$hole - 1] ?? ''];
        $build['scores_table']['back']['par'][$i] = ['#markup' => $par];

        $build['scores_table']['back']['score'][$i] = [
          '#type' => 'textfield',
          '#size' => 1,
          '#required' => FALSE,
          '#attributes' => [
            'pattern' => '[0-9]*',
            'min' => '0',
            'class' => ['gc-upload-score-input'],
            'data-hole' => (string) $hole,
          ],
          '#default_value' => $hole_score,
        ];

        $build['scores_table']['back']['putts'][$i] = [
          '#type' => 'textfield',
          '#size' => 1,
          '#required' => FALSE,
          '#attributes' => [
            'pattern' => '[0-9]*',
            'min' => '0',
            'class' => ['gc-upload-putts-input'],
            'data-hole' => (string) $hole,
          ],
          '#default_value' => $hole_putts,
        ];

        if (is_numeric($par) && (int) $par === 3) {
          $build['scores_table']['back']['fir'][$i] = ['#markup' => '—'];
        }
        else {
          $build['scores_table']['back']['fir'][$i] = [
            '#type' => 'select',
            '#options' => $fir_options,
            '#default_value' => $default_fir !== '' ? $default_fir : '',
            '#attributes' => [
              'class' => ['gc-upload-fir-input'],
              'data-hole' => (string) $hole,
            ],
          ];
        }

        $build['scores_table']['back']['penalty'][$i] = [
          '#type' => 'textfield',
          '#size' => 1,
          '#required' => FALSE,
          '#attributes' => [
            'pattern' => '[0-9]*',
            'min' => '0',
            'class' => ['gc-upload-penalty-input'],
            'data-hole' => (string) $hole,
          ],
          '#default_value' => $default_pen,
        ];

        $build['scores_table']['back']['updown'][$i] = [
          '#type' => 'checkbox',
          '#default_value' => $infer_updown ? 1 : 0,
          '#attributes' => [
            'class' => ['gc-upload-updown-input'],
            'data-hole' => (string) $hole,
          ],
        ];

        $build['scores_table']['back']['sandsave'][$i] = [
          '#type' => 'checkbox',
          '#default_value' => $infer_sand ? 1 : 0,
          '#attributes' => [
            'class' => ['gc-upload-sandsave-input'],
            'data-hole' => (string) $hole,
          ],
        ];
      }

      $build['scores_table']['back']['yards'][10] = ['#markup' => $yards_in];
      $build['scores_table']['back']['hdcp'][10] = ['#markup' => ''];
      $build['scores_table']['back']['par'][10] = ['#markup' => $par_in];
      $build['scores_table']['back']['score'][10] = ['#markup' => '<span id="scores_table_back_score">0</span>'];
      $build['scores_table']['back']['putts'][10] = ['#markup' => '<span id="scores_table_back_putts">0</span>'];
      $build['scores_table']['back']['fir'][10] = ['#markup' => ''];
      $build['scores_table']['back']['penalty'][10] = ['#markup' => ''];
      $build['scores_table']['back']['updown'][10] = ['#markup' => ''];
      $build['scores_table']['back']['sandsave'][10] = ['#markup' => ''];
    }

    // TOTALS TABLE (selected holes only)
    $build['scores_table']['total'] = [
      '#type' => 'table',
      '#header' => ['Gross Score', 'Par', 'Yards', 'Total Putts'],
      '#attributes' => ['class' => ['scorecard-table-input']],
    ];

    $totals = $this->calculateTotals($scores, $pars, $hole_list);
    $grossScore = (int) ($totals['gross'] ?? 0);
    $totalPutts = (int) ($totals['putts'] ?? 0);

    // Always show par_total that matches displayed holes.
    $parShown = (string) ($totals['par'] ?? '');
    $yardsShown = 0;
    foreach ($hole_list as $h) {
      $y = $yards[$h - 1] ?? NULL;
      if (is_numeric($y)) {
        $yardsShown += (int) $y;
      }
    }

    $build['scores_table']['total']['score']['gross'] = [
      '#markup' => "<span id='scores_table_total_score'>{$grossScore}</span>",
    ];
    $build['scores_table']['total']['score']['par'] = [
      '#markup' => '<span id="scores_table_par_score">' . htmlspecialchars($parShown, ENT_QUOTES, 'UTF-8') . '</span>',
    ];
    $build['scores_table']['total']['score']['yardage'] = [
      '#markup' => htmlspecialchars((string) $yardsShown, ENT_QUOTES, 'UTF-8'),
    ];
    $build['scores_table']['total']['score']['putts'] = [
      '#markup' => "<span id='scores_table_total_putts'>{$totalPutts}</span>",
    ];

    return $build;
  }

}
