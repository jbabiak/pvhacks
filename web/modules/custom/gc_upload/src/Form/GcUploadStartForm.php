<?php

namespace Drupal\gc_upload\Form;

use DOMDocument;
use DOMXPath;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

class GcUploadStartForm extends FormBase {

  protected $grintAPI;

  public function getFormId() {
    return 'gc_upload_start_form';
  }

  protected function logDebug(string $message, array $context = []): void {
    \Drupal::logger('gc_upload')->notice($message, $context);
  }

  protected function effectiveValue(FormStateInterface $form_state, array $form, string $key): string {
    $v = $form_state->getValue($key);
    if ($v !== NULL && trim((string) $v) !== '') {
      return trim((string) $v);
    }
    if (isset($form[$key]['#default_value']) && trim((string) $form[$key]['#default_value']) !== '') {
      return trim((string) $form[$key]['#default_value']);
    }
    return '';
  }

  protected function parseGrintRoundLabel(string $label): array {
    $out = [
      'course_name' => '',
      'facility_name' => '',
      'tee_name' => '',
    ];

    $label = trim($label);
    if ($label === '') {
      return $out;
    }

    // Remove trailing date part if present: " — Nov 2, 2025"
    $label_no_date = preg_replace('/\s+[—-]\s+[A-Za-z]{3,9}\s+\d{1,2},\s+\d{4}\s*$/u', '', $label);
    $label_no_date = trim((string) $label_no_date);

    // 1) "Score of 81 at Championship | Pine View Golf Course [Blue]"
    if (preg_match('/\bat\s+(.+?)\s*\|\s*(.+?)\s*\[([^\]]+)\]\s*$/i', $label_no_date, $m)) {
      $out['course_name'] = trim($m[1]);
      $out['facility_name'] = trim($m[2]);
      $out['tee_name'] = trim($m[3]);
      return $out;
    }

    // 2) "Score of 38 at Sand Point Golf Club [White]"
    if (preg_match('/\bat\s+(.+?)\s*\[([^\]]+)\]\s*$/i', $label_no_date, $m)) {
      $out['facility_name'] = trim($m[1]);
      $out['tee_name'] = trim($m[2]);
      return $out;
    }

    // 3) "... at Championship | Pine View Golf Course"
    if (preg_match('/\bat\s+(.+?)\s*\|\s*(.+?)\s*$/i', $label_no_date, $m)) {
      $out['course_name'] = trim($m[1]);
      $out['facility_name'] = trim($m[2]);
    }

    // 4) Tee only fallback: capture bracket
    if ($out['tee_name'] === '' && preg_match('/\[(.*?)\]/', $label_no_date, $m)) {
      $out['tee_name'] = trim($m[1]);
    }

    // 5) Loose fallback: whatever follows "at "
    if ($out['facility_name'] === '' && preg_match('/\bat\s+(.+)$/i', $label_no_date, $m)) {
      $after_at = trim($m[1]);
      $parts = array_map('trim', explode('|', $after_at));
      if (count($parts) >= 2) {
        $out['course_name'] = $out['course_name'] ?: $parts[0];
        $out['facility_name'] = $out['facility_name'] ?: preg_replace('/\[[^\]]+\]/', '', $parts[1]);
        $out['facility_name'] = trim((string) $out['facility_name']);
      }
      else {
        $out['facility_name'] = $out['facility_name'] ?: preg_replace('/\[[^\]]+\]/', '', $after_at);
        $out['facility_name'] = trim((string) $out['facility_name']);
      }
    }

    return $out;
  }

  protected function parseGrintDateTextToYmd(string $dateText): string {
    $dateText = trim($dateText);
    if ($dateText === '') {
      return '';
    }

    $ts = strtotime($dateText);
    if ($ts !== FALSE) {
      return date('Y-m-d', $ts);
    }

    $dt = \DateTime::createFromFormat('M j, Y', $dateText);
    if ($dt instanceof \DateTime) {
      return $dt->format('Y-m-d');
    }

    return '';
  }

  protected function extractTrailingId(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (preg_match('/^\(\s*(\d+)\s*\)\s*/', $value, $m)) {
      return (string) $m[1];
    }

    if (preg_match('/\((\d+)\)\s*$/', $value, $m)) {
      return (string) $m[1];
    }

    return '';
  }

  protected function normalizeName(string $s): string {
    $s = trim(mb_strtolower($s));
    if ($s === '') {
      return '';
    }

    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);

    $stop = [
      'the', 'golf', 'club', 'course', 'courses', 'country', 'cc',
      'gc', 'and', 'of', 'at', 'links',
    ];

    $parts = $s === '' ? [] : explode(' ', $s);
    $parts2 = [];
    foreach ($parts as $p) {
      if ($p === '' || in_array($p, $stop, TRUE)) {
        continue;
      }
      $parts2[] = $p;
    }

    return trim(implode(' ', $parts2));
  }

  protected function bestMatchByName(array $items, string $targetName): array {
    $targetName = trim($targetName);
    if ($targetName === '' || empty($items)) {
      return [];
    }

    foreach ($items as $it) {
      if (!isset($it['id'], $it['name'])) {
        continue;
      }
      if (strcasecmp(trim((string) $it['name']), $targetName) === 0) {
        return ['id' => (string) $it['id'], 'name' => (string) $it['name'], 'reason' => 'exact'];
      }
    }

    $nTarget = $this->normalizeName($targetName);
    if ($nTarget === '') {
      return [];
    }

    foreach ($items as $it) {
      if (!isset($it['id'], $it['name'])) {
        continue;
      }
      $n = $this->normalizeName((string) $it['name']);
      if ($n !== '' && $n === $nTarget) {
        return ['id' => (string) $it['id'], 'name' => (string) $it['name'], 'reason' => 'normalized_exact'];
      }
    }

    foreach ($items as $it) {
      if (!isset($it['id'], $it['name'])) {
        continue;
      }
      $n = $this->normalizeName((string) $it['name']);
      if ($n === '') {
        continue;
      }
      if (str_contains($n, $nTarget) || str_contains($nTarget, $n)) {
        return ['id' => (string) $it['id'], 'name' => (string) $it['name'], 'reason' => 'normalized_contains'];
      }
    }

    return [];
  }

  /**
   * NEW: Determine if a hole looks "played".
   * Grint often includes 18 keys, but unplayed holes are 0/empty.
   */
  protected function holeLooksPlayed(array $holeData): bool {
    // score > 0 is the strongest signal.
    if (isset($holeData['score']) && is_numeric($holeData['score']) && (int) $holeData['score'] > 0) {
      return TRUE;
    }

    // putts > 0 is also strong.
    if (isset($holeData['putts']) && is_numeric($holeData['putts']) && (int) $holeData['putts'] > 0) {
      return TRUE;
    }

    // Any of these non-empty can indicate activity.
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
   * NEW: Detect holes mode by looking at which holes are actually played.
   * Returns '18'|'front9'|'back9'
   */
  protected function detectHolesModeFromGrintScores($scores): string {
    if (!is_array($scores)) {
      return '18';
    }

    $played = [];
    for ($h = 1; $h <= 18; $h++) {
      if (!isset($scores[$h]) || !is_array($scores[$h])) {
        continue;
      }
      if ($this->holeLooksPlayed($scores[$h])) {
        $played[] = $h;
      }
    }

    $this->logDebug('detectHolesModeFromGrintScores(): played holes=@holes count=@c', [
      '@holes' => implode(',', $played),
      '@c' => count($played),
    ]);

    // If we can't confidently detect (0 played or lots), assume 18.
    if (count($played) === 0) {
      return '18';
    }

    // If > 11 played, almost certainly 18 (allows for a couple missing entries).
    if (count($played) > 11) {
      return '18';
    }

    $frontCount = 0;
    $backCount = 0;
    foreach ($played as $h) {
      if ($h <= 9) {
        $frontCount++;
      }
      else {
        $backCount++;
      }
    }

    // If played holes are mostly in one half, treat as that 9.
    if ($frontCount > 0 && $backCount === 0) {
      return 'front9';
    }
    if ($backCount > 0 && $frontCount === 0) {
      return 'back9';
    }

    // Mixed (rare): choose the larger side if total <= 10.
    if (count($played) <= 10) {
      return ($frontCount >= $backCount) ? 'front9' : 'back9';
    }

    return '18';
  }

  // --- UPDATED: safer facility fallback search variants (Course vs Club) ---
  protected function resolveGcSelections(FormStateInterface $form_state, string $memberId, array $grint_meta): void {
    $facilityName = trim((string) ($grint_meta['facility_name'] ?? ''));
    $courseName = trim((string) ($grint_meta['course_name'] ?? ''));
    $teeName = trim((string) ($grint_meta['tee_name'] ?? ''));

    $this->logDebug('resolveGcSelections() START memberId="@mid" facility="@f" course="@c" tee="@t"', [
      '@mid' => $memberId,
      '@f' => $facilityName,
      '@c' => $courseName,
      '@t' => $teeName,
    ]);

    $form_state->setValue('gc_facility_id', '');
    $form_state->setValue('gc_course_id', '');
    $form_state->setValue('gc_tee_id', '');

    if ($facilityName === '' || $memberId === '') {
      $this->logDebug('resolveGcSelections() EARLY RETURN: facilityName empty?=@fe memberId empty?=@me', [
        '@fe' => $facilityName === '' ? 'YES' : 'NO',
        '@me' => $memberId === '' ? 'YES' : 'NO',
      ]);
      return;
    }

    $facilityVariants = [];
    $facilityVariants[] = $facilityName;

    $v = $facilityName;
    if (preg_match('/\bgolf\s+course\b/i', $v)) {
      $facilityVariants[] = preg_replace('/\bgolf\s+course\b/i', 'Golf Club', $v);
    }
    if (preg_match('/\bgolf\s+club\b/i', $v)) {
      $facilityVariants[] = preg_replace('/\bgolf\s+club\b/i', 'Golf Course', $v);
    }

    $v2 = preg_replace('/\s+\bgolf\s+(?:club|course)\b\s*$/i', '', $facilityName);
    $v2 = trim((string) $v2);
    if ($v2 !== '' && $v2 !== $facilityName) {
      $facilityVariants[] = $v2;
    }

    $dedup = [];
    foreach ($facilityVariants as $q) {
      $q = trim((string) $q);
      if ($q === '') {
        continue;
      }
      $k = mb_strtolower($q);
      if (!isset($dedup[$k])) {
        $dedup[$k] = $q;
      }
    }
    $facilityVariants = array_values($dedup);

    try {
      /** @var \Drupal\gc_api\Service\GolfCanadaApiService $gc */
      $gc = \Drupal::service('gc_api.golf_canada_api_service');

      $facilities = [];
      $usedQuery = '';
      foreach ($facilityVariants as $q) {
        $usedQuery = $q;
        $facilities = $gc->searchFacilities($q, 10);

        $this->logDebug('GC facility search TRY q="@q" normalized="@nq" count=@c', [
          '@q' => $q,
          '@nq' => $this->normalizeName($q),
          '@c' => is_array($facilities) ? count($facilities) : 0,
        ]);

        if (is_array($facilities) && !empty($facilities)) {
          break;
        }
      }

      if (!is_array($facilities) || empty($facilities)) {
        $this->logDebug('GC facility search returned EMPTY/non-array after variants. variants="@v"', [
          '@v' => implode(' || ', $facilityVariants),
        ]);
        return;
      }

      $cand = [];
      foreach (array_slice($facilities, 0, 8) as $f) {
        if (!isset($f['id'], $f['name'])) {
          continue;
        }
        $cand[] = (string) $f['id'] . ':' . (string) $f['name'] . ' (n="' . $this->normalizeName((string) $f['name']) . '")';
      }
      $this->logDebug('GC facility candidates (top8) for usedQuery="@uq": @cand', [
        '@uq' => $usedQuery,
        '@cand' => implode(' | ', $cand),
      ]);

      $facilityId = '';
      $facilityPickedName = '';
      $facilityReason = '';

      $best = $this->bestMatchByName($facilities, $facilityName);
      if (empty($best['id']) && $usedQuery !== '' && $usedQuery !== $facilityName) {
        $best = $this->bestMatchByName($facilities, $usedQuery);
      }

      if (!empty($best['id'])) {
        $facilityId = (string) $best['id'];
        $facilityPickedName = (string) ($best['name'] ?? '');
        $facilityReason = (string) ($best['reason'] ?? '');
      }
      else {
        $facilityId = !empty($facilities[0]['id']) ? (string) $facilities[0]['id'] : '';
        $facilityPickedName = (string) ($facilities[0]['name'] ?? '');
        $facilityReason = 'first_result';
      }

      if ($facilityId === '') {
        $this->logDebug('GC facility NOT resolved for "@fn"', ['@fn' => $facilityName]);
        return;
      }

      $form_state->setValue('gc_facility_id', $facilityId);

      $this->logDebug('GC facility RESOLVED id=@id name="@nm" reason=@r usedQuery="@uq"', [
        '@id' => $facilityId,
        '@nm' => $facilityPickedName,
        '@r' => $facilityReason,
        '@uq' => $usedQuery,
      ]);

      $courses = $gc->getCourses((int) $facilityId, (int) $memberId);

      if (!is_array($courses) || empty($courses)) {
        $this->logDebug('GC getCourses returned EMPTY/non-array for facility=@f member=@m', [
          '@f' => $facilityId,
          '@m' => $memberId,
        ]);
        return;
      }

      $ccand = [];
      foreach (array_slice($courses, 0, 8) as $c) {
        if (!isset($c['id'], $c['name'])) {
          continue;
        }
        $ccand[] = (string) $c['id'] . ':' . (string) $c['name'] . ' (n="' . $this->normalizeName((string) $c['name']) . '")';
      }
      $this->logDebug('GC course candidates (top8): @cand', ['@cand' => implode(' | ', $ccand)]);

      $courseId = '';
      $coursePickedName = '';
      $courseReason = '';

      if ($courseName !== '') {
        $bestCourse = $this->bestMatchByName($courses, $courseName);
        if (!empty($bestCourse['id'])) {
          $courseId = (string) $bestCourse['id'];
          $coursePickedName = (string) ($bestCourse['name'] ?? '');
          $courseReason = (string) ($bestCourse['reason'] ?? '');
        }
      }

      if ($courseId === '' && count($courses) === 1 && !empty($courses[0]['id'])) {
        $courseId = (string) $courses[0]['id'];
        $coursePickedName = (string) ($courses[0]['name'] ?? '');
        $courseReason = 'single_course';
      }

      if ($courseId === '' && !empty($courses[0]['id'])) {
        $courseId = (string) $courses[0]['id'];
        $coursePickedName = (string) ($courses[0]['name'] ?? '');
        $courseReason = 'first_course';
      }

      $teeId = '';
      $teePickedName = '';
      $teeReason = '';

      foreach ($courses as $c) {
        if ((string) ($c['id'] ?? '') !== $courseId) {
          continue;
        }

        $tees = (!empty($c['tees']) && is_array($c['tees'])) ? $c['tees'] : [];

        $tcand = [];
        foreach (array_slice($tees, 0, 12) as $t) {
          if (!isset($t['id'], $t['name'])) {
            continue;
          }
          $tcand[] = (string) $t['id'] . ':' . (string) $t['name'] . ' (n="' . $this->normalizeName((string) $t['name']) . '")';
        }
        $this->logDebug('GC tee candidates for course="@cn" (top12): @cand', [
          '@cn' => (string) ($c['name'] ?? ''),
          '@cand' => implode(' | ', $tcand),
        ]);

        if ($teeName !== '' && !empty($tees)) {
          $bestTee = $this->bestMatchByName($tees, $teeName);
          if (!empty($bestTee['id'])) {
            $teeId = (string) $bestTee['id'];
            $teePickedName = (string) ($bestTee['name'] ?? '');
            $teeReason = (string) ($bestTee['reason'] ?? '');
          }
        }

        if ($teeId === '' && count($tees) === 1 && !empty($tees[0]['id'])) {
          $teeId = (string) $tees[0]['id'];
          $teePickedName = (string) ($tees[0]['name'] ?? '');
          $teeReason = 'single_tee';
        }

        if ($teeId === '' && !empty($tees[0]['id'])) {
          $teeId = (string) $tees[0]['id'];
          $teePickedName = (string) ($tees[0]['name'] ?? '');
          $teeReason = 'first_tee';
        }

        break;
      }

      $form_state->setValue('gc_course_id', $courseId);
      $form_state->setValue('gc_tee_id', $teeId);

      $this->logDebug('GC resolved IDs facility=@f course=@c tee=@t', [
        '@f' => $facilityId,
        '@c' => $courseId,
        '@t' => $teeId,
      ]);

      $this->logDebug('GC name match facility(grint)="@fn" => "@fcn" (@fr) | course(grint)="@cn" => "@ccn" (@cr) | tee(grint)="@tn" => "@tcn" (@tr)', [
        '@fn' => $facilityName,
        '@fcn' => $facilityPickedName,
        '@fr' => $facilityReason,
        '@cn' => $courseName,
        '@ccn' => $coursePickedName,
        '@cr' => $courseReason,
        '@tn' => $teeName,
        '@tcn' => $teePickedName,
        '@tr' => $teeReason,
      ]);
    }
    catch (\Throwable $e) {
      $this->logDebug('GC resolve error: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  protected function applyPostFieldsStateChanges(array &$form, FormStateInterface $form_state): void {
    $mode = (string) $form_state->get('mode');
    if ($mode !== 'loaded') {
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    $trigger_name = (string) ($trigger['#name'] ?? '');

    if ($trigger_name === 'club') {
      $club_value = trim((string) $form_state->getValue('club'));
      $facility_id = $this->extractTrailingId($club_value);

      $this->logDebug('Club changed: club="@club" resolved facility_id="@fid" -> clearing course/tee', [
        '@club' => $club_value,
        '@fid' => $facility_id,
      ]);

      $form_state->setValue('gc_facility_id', $facility_id);

      $form_state->setValue('gc_course_id', '');
      $form_state->setValue('gc_tee_id', '');

      $form_state->setValue('course', '');
      $form_state->setValue('tee', '');

      $input = $form_state->getUserInput();
      $input['course'] = '';
      $input['tee'] = '';
      $form_state->setUserInput($input);

      return;
    }

    if ($trigger_name === 'course') {
      $course_id = (string) $form_state->getValue('course');
      $this->logDebug('Course changed: course_id="@cid"', ['@cid' => $course_id]);

      $form_state->setValue('gc_course_id', $course_id);
      $form_state->setValue('gc_tee_id', '');
      return;
    }

    if ($trigger_name === 'tee') {
      $tee_id = (string) $form_state->getValue('tee');
      $this->logDebug('Tee changed: tee_id="@tid"', ['@tid' => $tee_id]);
      $form_state->setValue('gc_tee_id', $tee_id);
      return;
    }

    if ($trigger_name === 'holes_mode') {
      $hm = (string) $form_state->getValue('holes_mode');
      $this->logDebug('Holes mode changed: holes_mode="@hm"', ['@hm' => $hm]);
      return;
    }
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->grintAPI = \Drupal::service('grint_api.grint_api_service');

    if (!$form_state->has('mode')) {
      $form_state->set('mode', 'choose');
    }
    if (!$form_state->has('round_wave')) {
      $form_state->set('round_wave', 0);
    }

    $mode = (string) $form_state->get('mode');

    if ($form_state->isRebuilding() && $mode === 'loaded') {
      $this->applyPostFieldsStateChanges($form, $form_state);
    }

    $account = $this->currentUser();
    $defaults = [
      'grint_user_id' => '',
      'gc_id' => '',
    ];

    if ($account->isAuthenticated()) {
      $user = User::load($account->id());
      if ($user) {
        if ($user->hasField('field_grint_userid') && !$user->get('field_grint_userid')->isEmpty()) {
          $defaults['grint_user_id'] = (string) $user->get('field_grint_userid')->value;
        }
        if ($user->hasField('field_gc_id') && !$user->get('field_gc_id')->isEmpty()) {
          $defaults['gc_id'] = (string) $user->get('field_gc_id')->value;
        }
      }
    }

    $form['#prefix'] = '<div id="gc-upload-start-form">';
    $form['#suffix'] = '</div>';

    $header_type = ($mode === 'loaded') ? 'hidden' : 'textfield';

    $form['grint_user_id'] = [
      '#type' => $header_type,
      '#title' => $this->t('Grint User ID'),
      '#default_value' => $form_state->getValue('grint_user_id') ?? $defaults['grint_user_id'],
      '#ajax' => [
        'callback' => '::ajaxRefreshForm',
        'wrapper' => 'gc-upload-start-form',
        'event' => 'change',
        'progress' => ['type' => 'throbber'],
      ],
    ];

    $form['gc_id'] = [
      '#type' => $header_type,
      '#title' => $this->t('Golf Canada ID'),
      '#default_value' => $form_state->getValue('gc_id') ?? $defaults['gc_id'],
      '#ajax' => [
        'callback' => '::ajaxRefreshForm',
        'wrapper' => 'gc-upload-start-form',
        'event' => 'change',
        'progress' => ['type' => 'throbber'],
      ],
    ];

    $form['round_wave'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->get('round_wave') ?? 0),
    ];

    $form['loaded_round_id'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('loaded_round_id') ?? ''),
    ];

    $form['grint_facility_name'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('grint_facility_name') ?? ''),
    ];
    $form['grint_course_name'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('grint_course_name') ?? ''),
    ];
    $form['grint_tee_name'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('grint_tee_name') ?? ''),
    ];

    $form['gc_facility_id'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('gc_facility_id') ?? ''),
    ];
    $form['gc_course_id'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('gc_course_id') ?? ''),
    ];
    $form['gc_tee_id'] = [
      '#type' => 'hidden',
      '#value' => (string) ($form_state->getValue('gc_tee_id') ?? ''),
    ];

    $form['rounds_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'gc-upload-rounds-wrapper'],
    ];

    $form['rounds_wrapper'] += $this->buildRoundsSection($form, $form_state);

    return $form;
  }

  protected function buildRoundsSection(array &$form, FormStateInterface $form_state): array {
    $section = [];

    $mode = (string) $form_state->get('mode');

    $grint_uid = $this->effectiveValue($form_state, $form, 'grint_user_id');
    $gc_id = $this->effectiveValue($form_state, $form, 'gc_id');

    $this->logDebug('Header effective values: grint_uid=@grint_uid gc_id=@gc_id mode=@mode wave=@wave', [
      '@grint_uid' => $grint_uid,
      '@gc_id' => $gc_id,
      '@mode' => $mode,
      '@wave' => (int) ($form_state->get('round_wave') ?? 0),
    ]);

    if ($grint_uid === '' || $gc_id === '') {
      $section['help'] = [
        '#type' => 'markup',
        '#markup' => '<div class="gc-upload-help">Fill in Grint User ID and Golf Canada ID to load rounds.</div>',
      ];
      return $section;
    }

    if ($mode === 'loaded') {
      $loaded_round_id = (int) $form_state->getValue('loaded_round_id');

      $section['actions_top'] = ['#type' => 'actions'];
      $section['actions_top']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back (Choose Different Round)'),
        '#submit' => ['::backToRoundChooser'],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'gc-upload-start-form',
          'progress' => ['type' => 'throbber'],
        ],
        '#limit_validation_errors' => [],
      ];

      $section['loaded_header'] = [
        '#type' => 'markup',
        '#markup' => '<div class="gc-upload-loaded"><strong>Loaded round:</strong> ' . (int) $loaded_round_id . '</div>',
      ];

      $section['loaded_wrap'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'gc-upload-loaded-wrap',
          'class' => ['gc-upload-scorecard'],
        ],
      ];

      try {
        $scores = $this->grintAPI->getRoundScore($loaded_round_id);

        $this->logDebug('getRoundScore returned type=@type keys=@keys', [
          '@type' => gettype($scores),
          '@keys' => is_array($scores) ? implode(',', array_slice(array_keys($scores), 0, 25)) : '',
        ]);

        if (!is_array($scores)) {
          throw new \RuntimeException('getRoundScore did not return an array.');
        }

        // NEW: robust 9-hole detection based on played holes (not present keys).
        $auto_key = 'auto_holes_mode_for_round_' . (int) $loaded_round_id;
        if (!$form_state->has($auto_key)) {
          $detected = $this->detectHolesModeFromGrintScores($scores);
          $form_state->set($auto_key, $detected);

          $this->logDebug('Detected holes_mode="@m" for round @rid', [
            '@m' => $detected,
            '@rid' => (int) $loaded_round_id,
          ]);

          // Only set holes_mode if user hasn't already chosen one.
          $existing = (string) ($form_state->getValue('holes_mode') ?? '');
          if ($existing === '') {
            $form_state->setValue('holes_mode', $detected);
          }
        }

        $effective_holes_mode = (string) ($form_state->getValue('holes_mode') ?? $form_state->get($auto_key) ?? '18');
        if (!in_array($effective_holes_mode, ['18', 'front9', 'back9'], TRUE)) {
          $effective_holes_mode = '18';
        }

        $builder = \Drupal::service('gc_upload.scorecard_builder');

        $meta = [
          'course_id' => '',
          'tee_color' => (string) ($form_state->getValue('grint_tee_name') ?? ''),
          'course_name' => (string) ($form_state->getValue('grint_course_name') ?? ''),
          'facility_name' => (string) ($form_state->getValue('grint_facility_name') ?? ''),

          'gc_id' => (int) ($form_state->getValue('gc_id') ?? 0),
          'gc_facility_id' => (int) ($form_state->getValue('gc_facility_id') ?? 0),
          'gc_course_id' => (int) ($form_state->getValue('gc_course_id') ?? $form_state->getValue('course') ?? 0),
          'gc_tee_id' => (int) ($form_state->getValue('gc_tee_id') ?? $form_state->getValue('tee') ?? 0),

          // IMPORTANT
          'holes_mode' => $effective_holes_mode,
        ];

        $section['loaded_wrap']['scorecard'] = $builder->buildScorecard($scores, $meta, $grint_uid);

        $section['loaded_wrap']['post_fields_wrapper'] = [
          '#type' => 'container',
          '#attributes' => ['id' => 'gc-upload-post-fields-wrapper'],
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields'] = [
          '#type' => 'details',
          '#title' => $this->t('Post Score Details'),
          '#open' => TRUE,
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['club'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Club'),
          '#default_value' => (string) ($form_state->getValue('club') ?? $form_state->getValue('grint_facility_name') ?? ''),
          '#autocomplete_route_name' => 'gc_api.facility_autocomplete',
          '#description' => $this->t('Start typing the club name.'),
          '#ajax' => [
            'callback' => '::ajaxRefreshLoadedWrap',
            'wrapper' => 'gc-upload-loaded-wrap',
            'event' => 'autocompleteclose',
            'progress' => ['type' => 'throbber'],
          ],
        ];

        $course_options = ['' => $this->t('- Select -')];
        $tee_options = ['' => $this->t('- Select -')];

        $facility_id = (string) ($form_state->getValue('gc_facility_id') ?? '');
        $selected_course_id = (string) ($form_state->getValue('course') ?? $form_state->getValue('gc_course_id') ?? '');
        $selected_tee_id = (string) ($form_state->getValue('tee') ?? $form_state->getValue('gc_tee_id') ?? '');

        $courses_payload = [];
        if ($facility_id !== '') {
          try {
            /** @var \Drupal\gc_api\Service\GolfCanadaApiService $gc */
            $gc = \Drupal::service('gc_api.golf_canada_api_service');
            $courses_payload = $gc->getCourses((int) $facility_id, (int) $gc_id);
          }
          catch (\Throwable $e) {
            $this->logDebug('Error fetching GC courses: @msg', ['@msg' => $e->getMessage()]);
          }
        }

        if (is_array($courses_payload)) {
          foreach ($courses_payload as $c) {
            if (isset($c['id'], $c['name'])) {
              $course_options[(string) $c['id']] = (string) $c['name'];
            }
          }

          if ($selected_course_id !== '') {
            foreach ($courses_payload as $c) {
              if ((string) ($c['id'] ?? '') === $selected_course_id && !empty($c['tees']) && is_array($c['tees'])) {
                foreach ($c['tees'] as $t) {
                  if (isset($t['id'], $t['name'])) {
                    $tee_options[(string) $t['id']] = (string) $t['name'];
                  }
                }
                break;
              }
            }
          }
        }

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['course'] = [
          '#type' => 'select',
          '#title' => $this->t('Course'),
          '#options' => $course_options,
          '#default_value' => $selected_course_id,
          '#ajax' => [
            'callback' => '::ajaxRefreshLoadedWrap',
            'wrapper' => 'gc-upload-loaded-wrap',
            'event' => 'change',
            'progress' => ['type' => 'throbber'],
          ],
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['tee'] = [
          '#type' => 'select',
          '#title' => $this->t('Tee'),
          '#options' => $tee_options,
          '#default_value' => $selected_tee_id,
          '#ajax' => [
            'callback' => '::ajaxRefreshLoadedWrap',
            'wrapper' => 'gc-upload-loaded-wrap',
            'event' => 'change',
            'progress' => ['type' => 'throbber'],
          ],
        ];

        $detected_mode = (string) ($form_state->get($auto_key) ?? '18');
        $is_detected_9 = in_array($detected_mode, ['front9', 'back9'], TRUE);

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['holes_mode'] = [
          '#type' => 'radios',
          '#title' => $this->t('Holes'),
          '#options' => $is_detected_9 ? [
            'front9' => $this->t('Front 9'),
            'back9' => $this->t('Back 9'),
          ] : [
            '18' => $this->t('18 Holes'),
            'front9' => $this->t('Front 9'),
            'back9' => $this->t('Back 9'),
          ],
          '#default_value' => $effective_holes_mode,
          '#ajax' => [
            'callback' => '::ajaxRefreshLoadedWrap',
            'wrapper' => 'gc-upload-loaded-wrap',
            'event' => 'change',
            'progress' => ['type' => 'throbber'],
          ],
        ];

        $today = new DrupalDateTime('now');
        $selected_round_date = (string) ($form_state->get('selected_round_date_ymd') ?? '');
        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['played_date'] = [
          '#type' => 'date',
          '#title' => $this->t('Date'),
          '#default_value' => (string) ($form_state->getValue('played_date') ?? ($selected_round_date !== '' ? $selected_round_date : $today->format('Y-m-d'))),
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['format'] = [
          '#type' => 'radios',
          '#title' => $this->t('Format'),
          '#options' => [
            'stroke' => $this->t('Stroke Play'),
            'match' => $this->t('Match Play'),
          ],
          '#default_value' => (string) ($form_state->getValue('format') ?? 'stroke'),
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['tournament_score'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Tournament Score'),
          '#default_value' => (int) ($form_state->getValue('tournament_score') ?? 0),
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['attestor'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Attestor'),
          '#default_value' => (string) ($form_state->getValue('attestor') ?? ''),
        ];

        $section['loaded_wrap']['post_fields_wrapper']['post_fields']['played_alone'] = [
          '#type' => 'radios',
          '#title' => $this->t('Played Alone'),
          '#options' => [
            'no' => $this->t('No'),
            'yes' => $this->t('Yes'),
          ],
          '#default_value' => (string) ($form_state->getValue('played_alone') ?? 'no'),
        ];

        $section['loaded_wrap']['actions_bottom'] = ['#type' => 'actions'];
        $section['loaded_wrap']['actions_bottom']['post_score'] = [
          '#type' => 'submit',
          '#value' => $this->t('Post Score'),
          '#button_type' => 'primary',
          '#submit' => ['::postScoreSubmit'],
        ];
      }
      catch (\Throwable $e) {
        $this->logDebug('Error building loaded scorecard: @msg', ['@msg' => $e->getMessage()]);
        $section['error'] = [
          '#type' => 'markup',
          '#markup' => '<div class="gc-upload-error">Could not load scorecard. Check logs.</div>',
        ];
      }

      return $section;
    }

    // CHOOSE MODE (with paging via wave).
    try {
      $wave = (int) ($form_state->get('round_wave') ?? 0);
      $html = $this->grintAPI->getRoundFeed($grint_uid, $wave > 0 ? $wave : NULL);

      $rounds = $this->extractRounds($html);
      $rounds = array_slice($rounds, 0, 30);

      if (empty($rounds)) {
        $section['none'] = [
          '#type' => 'markup',
          '#markup' => '<div class="gc-upload-none">No rounds found for that user.</div>',
        ];
        return $section;
      }

      $section['round_pager'] = [
        '#type' => 'actions',
        '#attributes' => ['class' => ['gc-upload-round-pager']],
      ];

      $section['round_pager']['older'] = [
        '#type' => 'submit',
        '#value' => $this->t('Older rounds'),
        '#submit' => ['::roundPagerOlderSubmit'],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'gc-upload-start-form',
          'progress' => ['type' => 'throbber'],
        ],
        '#limit_validation_errors' => [
          ['grint_user_id'],
          ['gc_id'],
        ],
      ];
      $section['round_pager']['newer'] = [
        '#type' => 'submit',
        '#value' => $this->t('Newer rounds'),
        '#submit' => ['::roundPagerNewerSubmit'],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'gc-upload-start-form',
          'progress' => ['type' => 'throbber'],
        ],
        '#limit_validation_errors' => [
          ['grint_user_id'],
          ['gc_id'],
        ],
        '#disabled' => ($wave <= 0),
      ];

      $options = [];
      $round_meta_map = [];
      $round_date_map = [];

      foreach ($rounds as $round) {
        $rid = (string) $round['numberPost'];
        $label = trim((string) ($round['linkText'] ?? ''));
        $dateText = trim((string) ($round['dateText'] ?? ''));

        $display = ($label !== '' ? $label : ('Round ' . $rid)) . ($dateText !== '' ? (' — ' . $dateText) : '');
        $options[$rid] = $display;

        $round_meta_map[$rid] = $this->parseGrintRoundLabel($label);
        $round_date_map[$rid] = [
          'dateText' => $dateText,
          'ymd' => $this->parseGrintDateTextToYmd($dateText),
        ];
      }

      $form_state->set('round_meta_map', $round_meta_map);
      $form_state->set('round_date_map', $round_date_map);

      $default_selected = (string) ($form_state->getValue('selected_round') ?: array_key_first($options));

      $section['selected_round'] = [
        '#type' => 'radios',
        '#title' => $this->t('Choose a round'),
        '#options' => $options,
        '#default_value' => $default_selected,
      ];

      $section['actions'] = ['#type' => 'actions'];
      $section['actions']['load_round'] = [
        '#type' => 'submit',
        '#value' => $this->t('Load round'),
        '#button_type' => 'primary',
        '#submit' => ['::loadRoundSubmit'],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'gc-upload-start-form',
          'progress' => ['type' => 'throbber'],
        ],
        '#limit_validation_errors' => [
          ['grint_user_id'],
          ['gc_id'],
          ['selected_round'],
        ],
      ];
    }
    catch (\Throwable $e) {
      $this->logDebug('Error loading rounds: @msg', ['@msg' => $e->getMessage()]);
      $section['error'] = [
        '#type' => 'markup',
        '#markup' => '<div class="gc-upload-error">Could not load rounds (API error). Check logs.</div>',
      ];
    }

    return $section;
  }

  public function ajaxRefreshForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form;
  }

  public function ajaxRefreshLoadedWrap(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['rounds_wrapper']['loaded_wrap'];
  }

  public function ajaxRefreshPostFields(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form['rounds_wrapper']['loaded_wrap']['post_fields_wrapper'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Keep simple for now.
  }

  public function roundPagerOlderSubmit(array &$form, FormStateInterface $form_state) {
    $wave = (int) ($form_state->get('round_wave') ?? 0);
    $form_state->set('round_wave', $wave + 1);
    $form_state->setValue('selected_round', NULL);
    $form_state->setRebuild(TRUE);
  }

  public function roundPagerNewerSubmit(array &$form, FormStateInterface $form_state) {
    $wave = (int) ($form_state->get('round_wave') ?? 0);
    $form_state->set('round_wave', max(0, $wave - 1));
    $form_state->setValue('selected_round', NULL);
    $form_state->setRebuild(TRUE);
  }

  public function loadRoundSubmit(array &$form, FormStateInterface $form_state) {
    $selected_round = (string) $form_state->getValue('selected_round');
    $gc_id = (string) $form_state->getValue('gc_id');

    $this->logDebug('Load round clicked. selected_round=@rid gc_id=@gc', [
      '@rid' => $selected_round,
      '@gc' => $gc_id,
    ]);

    $form_state->setValue('loaded_round_id', (int) $selected_round);

    $round_meta_map = (array) $form_state->get('round_meta_map');
    $meta = $round_meta_map[$selected_round] ?? ['facility_name' => '', 'course_name' => '', 'tee_name' => ''];

    $form_state->setValue('grint_facility_name', (string) ($meta['facility_name'] ?? ''));
    $form_state->setValue('grint_course_name', (string) ($meta['course_name'] ?? ''));
    $form_state->setValue('grint_tee_name', (string) ($meta['tee_name'] ?? ''));

    $this->logDebug('Grint meta from label: facility="@f" course="@c" tee="@t"', [
      '@f' => (string) ($meta['facility_name'] ?? ''),
      '@c' => (string) ($meta['course_name'] ?? ''),
      '@t' => (string) ($meta['tee_name'] ?? ''),
    ]);

    $this->logDebug('About to call resolveGcSelections() ...');
    $this->resolveGcSelections($form_state, $gc_id, $meta);
    $this->logDebug('After resolveGcSelections(): gc_facility_id=@f gc_course_id=@c gc_tee_id=@t', [
      '@f' => (string) ($form_state->getValue('gc_facility_id') ?? ''),
      '@c' => (string) ($form_state->getValue('gc_course_id') ?? ''),
      '@t' => (string) ($form_state->getValue('gc_tee_id') ?? ''),
    ]);

    $round_date_map = (array) $form_state->get('round_date_map');
    $ymd = (string) ($round_date_map[$selected_round]['ymd'] ?? '');
    if ($ymd !== '') {
      $form_state->set('selected_round_date_ymd', $ymd);
      $form_state->setValue('played_date', $ymd);
      $this->logDebug('Selected round date parsed: @ymd', ['@ymd' => $ymd]);
    }
    else {
      $form_state->set('selected_round_date_ymd', '');
      $this->logDebug('Selected round date could not be parsed.');
    }

    // Clear previous choice so detection applies cleanly on load.
    $form_state->setValue('holes_mode', NULL);

    $form_state->set('mode', 'loaded');
    $form_state->setRebuild(TRUE);
  }

  public function backToRoundChooser(array &$form, FormStateInterface $form_state) {
    $this->logDebug('Back to chooser clicked.');
    $form_state->set('mode', 'choose');
    $form_state->setValue('loaded_round_id', '');
    $form_state->setValue('grint_facility_name', '');
    $form_state->setValue('grint_course_name', '');
    $form_state->setValue('grint_tee_name', '');
    $form_state->setValue('gc_facility_id', '');
    $form_state->setValue('gc_course_id', '');
    $form_state->setValue('gc_tee_id', '');
    $form_state->setValue('holes_mode', NULL);
    $form_state->setRebuild(TRUE);
  }

  public function postScoreSubmit(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\gc_upload\Service\GcUploadPostScorePayloadBuilder $builder */
    $builder = \Drupal::service('gc_upload.post_score_payload_builder');
    $payload = $builder->build($form_state);

    /** @var \Drupal\gc_api\Service\GolfCanadaApiService $gc */
    $gc = \Drupal::service('gc_api.golf_canada_api_service');
    $result = $gc->postScore($payload);

    $this->logDebug("GC Upload payload JSON:\n@json", [
      '@json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ]);
    $this->logDebug("GC postScore() result:\n@json", [
      '@json' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ]);

    if (!empty($result['ok'])) {
      \Drupal::messenger()->addStatus($this->t('Score posted to Golf Canada successfully.'));
    }
    else {
      $msg = !empty($result['error']) ? (string) $result['error'] : 'Unknown error';
      \Drupal::messenger()->addError($this->t('Golf Canada post failed: @msg', ['@msg' => $msg]));
    }
  }

  protected function extractRounds($html): array {
    $rounds = [];

    $dom = new DOMDocument();
    $this->loadHtmlAsUtf8($dom, (string) $html);

    $xpath = new DOMXPath($dom);
    $divNodes = $xpath->query("//div[contains(@class, 'newsfeed-container')][@number-post]");

    foreach ($divNodes as $divNode) {
      $numberPost = $divNode->getAttribute('number-post');

      $linkNode = $xpath->query(".//a[contains(@class, 'newsfeed-link-message')]", $divNode)->item(0);
      $href = $linkNode ? (string) $linkNode->getAttribute('href') : '';
      $linkText = $this->nodeText($linkNode);

      $linkTextRemoved = $linkText !== '' ? substr($linkText, 9) : '';
      $linkTextRemoved = trim((string) $linkTextRemoved);

      $roundId = '';
      if ($href !== '' && preg_match('~/(?:score/)?review_score/(\d+)~', $href, $m)) {
        $roundId = (string) $m[1];
      }

      $effectiveId = $roundId !== '' ? $roundId : $numberPost;

      $dateNode = $xpath->query(".//span[contains(@class, 'newsfeed-date')]", $divNode)->item(0);
      $dateText = $this->nodeText($dateNode);

      if ((int) $effectiveId > 0) {
        $rounds[] = [
          'numberPost' => $effectiveId,
          'linkText' => ucfirst($linkTextRemoved),
          'dateText' => $dateText,
        ];
      }
    }

    return $rounds;
  }

  protected function loadHtmlAsUtf8(DOMDocument $dom, string $html): void {
    $enc = mb_detect_encoding($html, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], TRUE);
    if ($enc && $enc !== 'UTF-8') {
      $html = mb_convert_encoding($html, 'UTF-8', $enc);
    }

    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
  }

  protected function nodeText(?\DOMNode $node): string {
    if (!$node) {
      return '';
    }
    $text = trim((string) $node->textContent);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op.
  }

}
