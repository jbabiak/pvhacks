<?php

namespace Drupal\gc_upload\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

class GcUploadPostScorePayloadBuilder {

  protected ?LoggerChannelInterface $logger = NULL;

  public function __construct(LoggerChannelFactoryInterface $loggerFactory = NULL) {
    if ($loggerFactory) {
      $this->logger = $loggerFactory->get('gc_upload');
    }
  }

  protected function log(string $message, array $context = []): void {
    if ($this->logger) {
      $this->logger->notice($message, $context);
    }
    else {
      \Drupal::logger('gc_upload')->notice($message, $context);
    }
  }

  public function build(FormStateInterface $form_state): array {
    // IMPORTANT:
    // - getValues() can contain processed values / objects during AJAX rebuilds.
    // - getUserInput() contains the raw submitted arrays we need for scores_table.
    $values = $form_state->getValues();
    $input = $form_state->getUserInput();
    if (!is_array($input)) {
      $input = [];
    }

    $individualId = isset($values['gc_id']) ? (int) $values['gc_id'] : 0;
    $facilityId = isset($values['gc_facility_id']) ? (int) $values['gc_facility_id'] : 0;
    $courseId = isset($values['gc_course_id']) ? (int) $values['gc_course_id'] : 0;
    $teeId = isset($values['gc_tee_id']) ? (int) $values['gc_tee_id'] : 0;

    $played_date = (string) ($values['played_date'] ?? $values['scorecard_date'] ?? '');
    $date_iso = $this->dateToIsoNoMs($played_date);

    $holes_mode = (string) ($values['holes_mode'] ?? '18');
    $holesPlayed = $this->holesModeToGcEnum($holes_mode);
    $hole_list = $this->holesListFromMode($holes_mode);

    $format = (string) ($values['format'] ?? 'stroke');
    $formatPlayed = ($format === 'match') ? 'MatchPlay' : 'StrokePlay';

    $isTournament = !empty($values['tournament_score']);
    $isPlayedAlone = ((string) ($values['played_alone'] ?? 'no')) === 'yes';

    $attestor = trim((string) ($values['attestor'] ?? ''));
    $attestor = ($attestor === '') ? NULL : $attestor;

    // Extract scorecard stats from RAW INPUT (not $values).
    $gross_by_hole = $this->extractGrossByHoleFromSubmittedData($input);
    $putts_by_hole = $this->extractPuttsByHoleFromSubmittedData($input);
    $fir_by_hole = $this->extractFirByHoleFromSubmittedData($input);
    $updown_by_hole = $this->extractUpDownByHoleFromSubmittedData($input);     // 1 or NULL
    $sand_by_hole = $this->extractSandSaveByHoleFromSubmittedData($input);     // 1 or NULL
    $pen_by_hole = $this->extractPenaltyByHoleFromSubmittedData($input);

    $this->log('Payload build: holes_mode=@hm holesPlayed=@hp hole_list=@list gross_keys=@gk', [
      '@hm' => $holes_mode,
      '@hp' => $holesPlayed,
      '@list' => implode(',', $hole_list),
      '@gk' => implode(',', array_keys($gross_by_hole)),
    ]);

    // Build holeScores[] ONLY for holes played.
    $holeScores = [];
    foreach ($hole_list as $h) {
      $holeScores[] = [
        'number' => (int) $h,
        'gross' => $gross_by_hole[$h] ?? NULL,
        'putts' => $putts_by_hole[$h] ?? NULL,
        'puttLength' => NULL,
        'club' => NULL,
        'drive' => NULL,
        'fir' => $fir_by_hole[$h] ?? NULL,
        'upDown' => $updown_by_hole[$h] ?? NULL,
        'sandSave' => $sand_by_hole[$h] ?? NULL,
        'penalty' => $pen_by_hole[$h] ?? NULL,
        'max' => NULL,
      ];
    }

    // Log missing gross explicitly (GC will 400).
    $missing = [];
    foreach ($hole_list as $h) {
      if (!isset($gross_by_hole[$h]) || $gross_by_hole[$h] === NULL) {
        $missing[] = $h;
      }
    }
    if (!empty($missing)) {
      $this->log('WARNING: Missing gross for played holes: @holes', [
        '@holes' => implode(',', $missing),
      ]);
    }

    $esc = $this->sumGrossForHoles($gross_by_hole, $hole_list);

    // If you’re sending any of these fields, GC considers stats tracking enabled.
    $isTrackingStats = TRUE;

    return [
      'id' => NULL,
      'individualId' => $individualId ?: NULL,
      'date' => $date_iso,
      'courseId' => $courseId ?: NULL,
      'teeId' => $teeId ?: NULL,
      'holesPlayed' => $holesPlayed,
      'formatPlayed' => $formatPlayed,
      'esc' => $esc,
      'holeScores' => $holeScores,
      'isHoleByHole' => TRUE,
      'isHoleByHoleRequired' => FALSE,
      'isTrackingStats' => (bool) $isTrackingStats,
      'isTournament' => (bool) $isTournament,
      'isPenalty' => FALSE,
      'attestor' => $attestor,
      'isPlayedAlone' => (bool) $isPlayedAlone,
      'facilityId' => $facilityId ?: NULL,
    ];
  }

  // -------------------------
  // HOLE LIST / ENUMS
  // -------------------------

  protected function holesListFromMode(string $holes_mode): array {
    $holes_mode = trim($holes_mode);
    return match ($holes_mode) {
      'front9' => range(1, 9),
      'back9' => range(10, 18),
      default => range(1, 18),
    };
  }

  protected function holesModeToGcEnum(string $holes_mode): string {
    if ($holes_mode === 'front9') return 'FrontNine';
    if ($holes_mode === 'back9') return 'BackNine';
    return 'EighteenHoles';
  }

  // -------------------------
  // EXTRACTION (RAW SUBMITTED)
  // -------------------------

  protected function extractGrossByHoleFromSubmittedData(array $submitted): array {
    $scores_table = $this->getScoresTableFromSubmittedData($submitted);
    $gross_by_hole = [];

    if (!is_array($scores_table)) {
      return $gross_by_hole;
    }

    if (!empty($scores_table['front']['score']) && is_array($scores_table['front']['score'])) {
      foreach ($scores_table['front']['score'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $hole = (int) $k;
        if ($hole < 1 || $hole > 9) continue;
        $gross_by_hole[$hole] = $this->toNullableInt($v);
      }
    }

    if (!empty($scores_table['back']['score']) && is_array($scores_table['back']['score'])) {
      foreach ($scores_table['back']['score'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $idx = (int) $k;
        if ($idx < 1 || $idx > 9) continue;
        $hole = $idx + 9;
        $gross_by_hole[$hole] = $this->toNullableInt($v);
      }
    }

    return $gross_by_hole;
  }

  protected function extractPuttsByHoleFromSubmittedData(array $submitted): array {
    $scores_table = $this->getScoresTableFromSubmittedData($submitted);
    $putts_by_hole = [];

    if (!is_array($scores_table)) {
      return $putts_by_hole;
    }

    if (!empty($scores_table['front']['putts']) && is_array($scores_table['front']['putts'])) {
      foreach ($scores_table['front']['putts'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $hole = (int) $k;
        if ($hole < 1 || $hole > 9) continue;
        $putts_by_hole[$hole] = $this->toNullableInt($v);
      }
    }

    if (!empty($scores_table['back']['putts']) && is_array($scores_table['back']['putts'])) {
      foreach ($scores_table['back']['putts'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $idx = (int) $k;
        if ($idx < 1 || $idx > 9) continue;
        $hole = $idx + 9;
        $putts_by_hole[$hole] = $this->toNullableInt($v);
      }
    }

    return $putts_by_hole;
  }

  protected function extractFirByHoleFromSubmittedData(array $submitted): array {
    $scores_table = $this->getScoresTableFromSubmittedData($submitted);
    $out = [];

    if (!is_array($scores_table)) {
      return $out;
    }

    if (!empty($scores_table['front']['fir']) && is_array($scores_table['front']['fir'])) {
      foreach ($scores_table['front']['fir'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $hole = (int) $k;
        if ($hole < 1 || $hole > 9) continue;
        $enum = $this->sanitizeFirEnum($v);
        if ($enum !== NULL) {
          $out[$hole] = $enum;
        }
      }
    }

    if (!empty($scores_table['back']['fir']) && is_array($scores_table['back']['fir'])) {
      foreach ($scores_table['back']['fir'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $idx = (int) $k;
        if ($idx < 1 || $idx > 9) continue;
        $hole = $idx + 9;
        $enum = $this->sanitizeFirEnum($v);
        if ($enum !== NULL) {
          $out[$hole] = $enum;
        }
      }
    }

    return $out;
  }

  protected function extractUpDownByHoleFromSubmittedData(array $submitted): array {
    $scores_table = $this->getScoresTableFromSubmittedData($submitted);
    $out = [];

    if (!is_array($scores_table)) {
      return $out;
    }

    if (!empty($scores_table['front']['updown']) && is_array($scores_table['front']['updown'])) {
      foreach ($scores_table['front']['updown'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $hole = (int) $k;
        if ($hole < 1 || $hole > 9) continue;
        $n = $this->toCheckedInt1OrNull($v);
        if ($n !== NULL) {
          $out[$hole] = $n;
        }
      }
    }

    if (!empty($scores_table['back']['updown']) && is_array($scores_table['back']['updown'])) {
      foreach ($scores_table['back']['updown'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $idx = (int) $k;
        if ($idx < 1 || $idx > 9) continue;
        $hole = $idx + 9;
        $n = $this->toCheckedInt1OrNull($v);
        if ($n !== NULL) {
          $out[$hole] = $n;
        }
      }
    }

    return $out;
  }

  protected function extractSandSaveByHoleFromSubmittedData(array $submitted): array {
    $scores_table = $this->getScoresTableFromSubmittedData($submitted);
    $out = [];

    if (!is_array($scores_table)) {
      return $out;
    }

    if (!empty($scores_table['front']['sandsave']) && is_array($scores_table['front']['sandsave'])) {
      foreach ($scores_table['front']['sandsave'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $hole = (int) $k;
        if ($hole < 1 || $hole > 9) continue;
        $n = $this->toCheckedInt1OrNull($v);
        if ($n !== NULL) {
          $out[$hole] = $n;
        }
      }
    }

    if (!empty($scores_table['back']['sandsave']) && is_array($scores_table['back']['sandsave'])) {
      foreach ($scores_table['back']['sandsave'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $idx = (int) $k;
        if ($idx < 1 || $idx > 9) continue;
        $hole = $idx + 9;
        $n = $this->toCheckedInt1OrNull($v);
        if ($n !== NULL) {
          $out[$hole] = $n;
        }
      }
    }

    return $out;
  }

  protected function extractPenaltyByHoleFromSubmittedData(array $submitted): array {
    $scores_table = $this->getScoresTableFromSubmittedData($submitted);
    $out = [];

    if (!is_array($scores_table)) {
      return $out;
    }

    if (!empty($scores_table['front']['penalty']) && is_array($scores_table['front']['penalty'])) {
      foreach ($scores_table['front']['penalty'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $hole = (int) $k;
        if ($hole < 1 || $hole > 9) continue;
        $n = $this->toNullableInt($v);
        if ($n !== NULL) {
          $out[$hole] = $n;
        }
      }
    }

    if (!empty($scores_table['back']['penalty']) && is_array($scores_table['back']['penalty'])) {
      foreach ($scores_table['back']['penalty'] as $k => $v) {
        if (!is_numeric($k)) continue;
        $idx = (int) $k;
        if ($idx < 1 || $idx > 9) continue;
        $hole = $idx + 9;
        $n = $this->toNullableInt($v);
        if ($n !== NULL) {
          $out[$hole] = $n;
        }
      }
    }

    return $out;
  }

  // -------------------------
  // SCORES TABLE FINDER (RAW)
  // -------------------------

  protected function getScoresTableFromSubmittedData(array $submitted): ?array {
    // 1) First: submitted user input
    $scores_table = $this->findScoresTable($submitted);
    if (is_array($scores_table)) {
      return $scores_table;
    }

    // 2) Fallback: raw request
    $input = [];
    try {
      $input = \Drupal::request()->request->all();
    }
    catch (\Throwable $e) {
      $input = [];
    }

    $scores_table = $this->findScoresTable($input);
    return is_array($scores_table) ? $scores_table : NULL;
  }

  protected function findScoresTable($data): ?array {
    if (!is_array($data)) {
      return NULL;
    }

    // Direct match: scores_table subtree.
    if (isset($data['scores_table']) && is_array($data['scores_table'])) {
      if ($this->looksLikeScoresTable($data['scores_table'])) {
        return $data['scores_table'];
      }
    }

    // Or the current node itself.
    if ($this->looksLikeScoresTable($data)) {
      return $data;
    }

    // Recursive scan.
    foreach ($data as $v) {
      if (is_array($v)) {
        $found = $this->findScoresTable($v);
        if (is_array($found)) {
          return $found;
        }
      }
      // If $v is an object (TranslatableMarkup etc), ignore it.
    }

    return NULL;
  }

  /**
   * Allows front-only OR back-only (9-hole rounds).
   */
  protected function looksLikeScoresTable(array $t): bool {
    $hasFront = isset($t['front']) && is_array($t['front']);
    $hasBack  = isset($t['back']) && is_array($t['back']);

    if (!$hasFront && !$hasBack) {
      return FALSE;
    }

    $candidateSides = [];
    if ($hasFront) $candidateSides[] = $t['front'];
    if ($hasBack)  $candidateSides[] = $t['back'];

    foreach ($candidateSides as $side) {
      foreach (['score', 'putts', 'fir', 'updown', 'sandsave', 'penalty'] as $key) {
        if (isset($side[$key]) && is_array($side[$key])) {
          foreach ($side[$key] as $k => $v) {
            if (is_numeric($k)) {
              return TRUE;
            }
          }
        }
      }
    }

    return FALSE;
  }

  // -------------------------
  // SANITIZERS / HELPERS
  // -------------------------

  protected function sanitizeFirEnum($value): ?string {
    $s = trim((string) $value);
    if ($s === '') return NULL;

    $allowed = [
      'Hit',
      'MissedRight',
      'MissedLeft',
      'MissedShort',
      'MissedLong',
      'MissedUnspecified',
    ];

    return in_array($s, $allowed, TRUE) ? $s : NULL;
  }

  protected function toCheckedInt1OrNull($value): ?int {
    if ($value === TRUE) return 1;
    if ($value === 1) return 1;
    if ($value === '1') return 1;

    $s = trim((string) $value);
    if ($s === '') return NULL;
    if (strtolower($s) === 'true') return 1;
    if (strtolower($s) === 'on') return 1;

    return NULL;
  }

  protected function sumGrossForHoles(array $gross_by_hole, array $hole_list): ?int {
    $sum = 0;
    $count = 0;

    foreach ($hole_list as $h) {
      $v = $gross_by_hole[$h] ?? NULL;
      if (is_numeric($v)) {
        $sum += (int) $v;
        $count++;
      }
    }

    return $count > 0 ? $sum : NULL;
  }

  protected function toNullableInt($value): ?int {
    if ($value === NULL) return NULL;
    $s = trim((string) $value);
    if ($s === '') return NULL;
    if (!is_numeric($s)) return NULL;
    return (int) $s;
  }

  protected function dateToIsoNoMs(string $date): string {
    $date = trim($date);
    if ($date === '') return '';
    return $date . 'T00:00:00';
  }

}
