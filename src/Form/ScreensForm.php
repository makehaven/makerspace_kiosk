<?php

namespace Drupal\makerspace_kiosk\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makerspace_kiosk\ScreenRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assign each physical screen (by its Screen ID) to a display page.
 */
class ScreensForm extends FormBase {

  /**
   * Constructs the screens form.
   */
  public function __construct(
    protected ScreenRegistry $registry,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('makerspace_kiosk.screen_registry'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_kiosk_screens';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $assignments = $this->registry->assignments();
    $seen = $this->registry->seen();

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('A new screen shows its six-character <strong>Screen ID</strong> on the TV and appears here as soon as it is connected. Give it a name and the page it should show. The screen picks up a change at its next start (every night at 3am) or immediately when sent a <code>reload</code> command.') . '</p>',
    ];

    // New screens first: those are the ones someone is standing in front of.
    $ids = array_unique(array_merge(array_keys($seen), array_keys($assignments)));
    usort($ids, function ($a, $b) use ($assignments, $seen) {
      $new = (int) isset($assignments[$a]) <=> (int) isset($assignments[$b]);
      if ($new !== 0) {
        return $new;
      }
      return ($seen[$b]['last'] ?? 0) <=> ($seen[$a]['last'] ?? 0);
    });

    $form['screens'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Screen ID'),
        $this->t('Last checked in'),
        $this->t('Name'),
        $this->t('Shows'),
        $this->t('Rotation'),
        $this->t('Forget'),
      ],
      '#empty' => $this->t('No screens have checked in yet.'),
    ];
    $now = $this->time->getRequestTime();
    foreach ($ids as $id) {
      $a = $assignments[$id] ?? NULL;
      $s = $seen[$id] ?? NULL;
      $form['screens'][$id]['id'] = [
        '#markup' => '<strong><code>' . $id . '</code></strong>' . ($a ? '' : ' <em>' . $this->t('new') . '</em>'),
      ];
      $form['screens'][$id]['seen'] = [
        '#markup' => $s
          ? $this->t('@ago ago (v@v)', [
            '@ago' => $this->dateFormatter->formatInterval($now - $s['last'], 2),
            '@v' => $s['version'] ?: '?',
          ])
          : $this->t('never'),
      ];
      $form['screens'][$id]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#title_display' => 'invisible',
        '#default_value' => $a['label'] ?? '',
        '#size' => 20,
        '#placeholder' => $this->t('e.g. Main TV'),
      ];
      $form['screens'][$id]['url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Shows'),
        '#title_display' => 'invisible',
        '#default_value' => $a['url'] ?? '',
        '#size' => 45,
        '#maxlength' => 512,
        '#placeholder' => '/display/slideshow',
      ];
      $form['screens'][$id]['rotation'] = [
        '#type' => 'select',
        '#title' => $this->t('Rotation'),
        '#title_display' => 'invisible',
        '#options' => [0 => '0°', 90 => '90°', 180 => '180°', 270 => '270°'],
        '#default_value' => $a['rotation'] ?? 0,
      ];
      $form['screens'][$id]['forget'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Forget'),
        '#title_display' => 'invisible',
      ];
    }

    $form['add'] = [
      '#type' => 'details',
      '#title' => $this->t('Add a screen that has not checked in yet'),
      '#open' => empty($ids),
    ];
    $form['add']['add_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Screen ID'),
      '#size' => 8,
      '#maxlength' => 6,
    ];
    $form['add']['add_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#size' => 20,
    ];
    $form['add']['add_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shows'),
      '#size' => 45,
      '#maxlength' => 512,
    ];

    $form['displays'] = [
      '#type' => 'details',
      '#title' => $this->t('Pages a screen can show'),
      '#description' => $this->t('Any page on this site (starting with /) or an https:// address. Some displays need their code word on the end; see each display\'s settings.'),
      'list' => [
        '#theme' => 'item_list',
        '#items' => $this->knownDisplays(),
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save screens'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->getValue('screens') ?: [] as $id => $row) {
      $url = trim($row['url'] ?? '');
      if ($url !== '' && empty($row['forget']) && !ScreenRegistry::isValidUrl($url)) {
        $form_state->setErrorByName("screens][$id][url", $this->t('Screen @id: use a site path starting with / or an https:// address.', ['@id' => $id]));
      }
    }
    $add_id = trim((string) $form_state->getValue('add_id'));
    if ($add_id !== '') {
      if (ScreenRegistry::normalizeId($add_id) === NULL) {
        $form_state->setErrorByName('add_id', $this->t('A Screen ID is six characters, 0-9 and A-F.'));
      }
      if (!ScreenRegistry::isValidUrl((string) $form_state->getValue('add_url'))) {
        $form_state->setErrorByName('add_url', $this->t('Use a site path starting with / or an https:// address.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $assignments = [];
    foreach ($form_state->getValue('screens') ?: [] as $id => $row) {
      if (!empty($row['forget'])) {
        $this->registry->forget($id);
        continue;
      }
      $url = trim($row['url'] ?? '');
      if ($url !== '') {
        $assignments[$id] = [
          'label' => trim($row['label'] ?? '') ?: $id,
          'url' => $url,
          'rotation' => (int) $row['rotation'],
        ];
      }
    }
    $add_id = ScreenRegistry::normalizeId((string) $form_state->getValue('add_id'));
    if ($add_id !== NULL) {
      $assignments[$add_id] = [
        'label' => trim((string) $form_state->getValue('add_label')) ?: $add_id,
        'url' => trim((string) $form_state->getValue('add_url')),
        'rotation' => 0,
      ];
    }
    $this->registry->saveAssignments($assignments);
    $this->messenger()->addStatus($this->t('Screens saved. Each screen picks up its change at its next start, or right away with a reload command.'));
  }

  /**
   * Display pages known to exist on this site, as suggestions.
   *
   * @return string[]
   *   Paths with a short description.
   */
  protected function knownDisplays(): array {
    $items = ['/display/slideshow — ' . $this->t('in-shop slideshow')];
    $slideshow = $this->configFactory->get('makerspace_screen_slideshow.settings');
    foreach (array_keys($slideshow->get('screens') ?? []) as $key) {
      $items[] = '/display/slideshow/screen/' . $key . ' — ' . $this->t('slideshow profile "@key"', ['@key' => $key]);
    }
    $items[] = '/display/reservations/day — ' . $this->t("today's reservations");
    $items[] = '/display/access-request/{code word} — ' . $this->t('member faces');
    $items[] = '/facilitator-display/display/{code word} — ' . $this->t('facilitator board');
    $items[] = '/display/tasks/{code word} — ' . $this->t('tasks board');
    return $items;
  }

}
