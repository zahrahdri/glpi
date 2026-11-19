<?php

/**
 * Imports the four capacity request forms shipped in extras/forms (Administration > Forms), the same way as
 * Administration > Forms > Import, so a new server gets them without clicking through the import screens.
 *
 *   php bin/console plugins:projectreports:import-forms               import the forms that are not there yet
 *   php bin/console plugins:projectreports:import-forms --user=jdoe   users the forms name but this GLPI lacks -> jdoe
 *
 * A form whose name already exists is skipped, so running it twice changes nothing. The forms name the users
 * "admin" and "user" (who may fill the form, default technician); when this GLPI has no such user they are mapped
 * to --user, else to the first active Super-Admin.
 */

use Glpi\Console\AbstractCommand;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\FormSerializer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginProjectreportsImportformscommand extends AbstractCommand
{
    /**
     * Changes for forms that were imported before (run by the plugin install and by this command; safe to repeat):
     *  - all on one page (no "Continue" between the sections, only "Submit" at the end),
     *  - New project: shorter introduction.
     */
    public static function updateExisting(): int
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists('glpi_forms_forms')) {
            return 0;
        }
        $n = 0;
        $DB->update('glpi_forms_forms', ['render_layout' => 'single_page'], ['name' => ['LIKE', 'Capacity request: %'], 'render_layout' => 'step_by_step']);
        $n += $DB->affectedRows();
        $DB->update('glpi_forms_forms',
            ['header' => '<p>Submit one request per resource type (compute, storage or GPU).</p>'],
            ['name' => 'Capacity request: New project',
                'header' => '<p>Request infrastructure for a <b>new project</b> that does not exist in GLPI yet. Submit one request per resource type (compute, storage or GPU).</p>']);
        $n += $DB->affectedRows();

        // capacity request forms (not Release): no "Estimated cost (Rial)" question; new "What happens next" text
        $forms = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_forms_forms', 'WHERE' => ['name' => ['LIKE', 'Capacity request: %']]]) as $f) {
            $forms[(int) $f['id']] = $f['name'];
        }
        if ($forms) {
            $sections = array_column(iterator_to_array($DB->request(['SELECT' => ['id', 'forms_forms_id'], 'FROM' => 'glpi_forms_sections',
                'WHERE' => ['forms_forms_id' => array_keys($forms)]]), false), 'forms_forms_id', 'id');
            if ($sections) {
                foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_forms_questions',
                    'WHERE' => ['name' => 'Estimated cost (Rial)', 'forms_sections_id' => array_keys($sections)]]) as $q) {
                    // the answers already given stay in the tickets; the question is no longer asked
                    if ((new Glpi\Form\Question())->delete(['id' => (int) $q['id']], true)) {
                        $n++;
                    }
                }
                $old = '<li>Resources are delivered and the ticket is solved. You follow every step in the ticket.</li></ol>';
                foreach ($DB->request(['SELECT' => ['id', 'forms_sections_id', 'description'], 'FROM' => 'glpi_forms_comments',
                    'WHERE' => ['name' => 'What happens next', 'forms_sections_id' => array_keys($sections)]]) as $c) {
                    if (!str_contains((string) $c['description'], $old)) {
                        continue;
                    }
                    $form = $forms[(int) $sections[(int) $c['forms_sections_id']]] ?? '';
                    $plo = stripos($form, 'Expansion') !== false ? 'Hardware Expansion' : 'New Infrastructure';
                    $DB->update('glpi_forms_comments', ['description' => str_replace($old,
                        '<li>Resources are prepared and the ticket is solved.</li><li>You can raise "' . $plo . '" PLO in iCare for the delivery.</li></ol>',
                        (string) $c['description'])], ['id' => (int) $c['id']]);
                    $n += $DB->affectedRows();
                }
            }
        }
        return $n + self::syncNewItems();
    }

    /** questions renamed in extras/forms: old name => new name */
    private const RENAMED = ['Sizing / calculation evidence' => 'Other sizing notes'];

    /**
     * Brings installed capacity request forms up to date with extras/forms: sections and questions that the file
     * has and the form lacks (matched by name) are added, renamed questions take their new name/place, and the
     * section order follows the file. Existing questions and answers are otherwise left alone.
     */
    private static function syncNewItems(): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $n = 0;
        foreach (glob(__DIR__ . '/../extras/forms/capacity-request-*.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true)['forms'][0] ?? null;
            if (!$json) {
                continue;
            }
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_forms_forms', 'WHERE' => ['name' => $json['name']]]) as $form) {
                $n += self::syncForm((int) $form['id'], $json);
            }
        }
        return $n;
    }

    private static function syncForm(int $form_id, array $json): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $n = 0;
        $db_secs = [];   // name => row
        foreach ($DB->request(['FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => $form_id]]) as $s) {
            $db_secs[$s['name']] = $s;
        }
        if (!$db_secs) {
            return 0;
        }
        $db_qs = [];     // name => row
        foreach ($DB->request(['FROM' => 'glpi_forms_questions', 'WHERE' => ['forms_sections_id' => array_column($db_secs, 'id')]]) as $q) {
            $db_qs[$q['name']] = $q;
        }
        foreach (self::RENAMED as $old => $new) {
            if (isset($db_qs[$old]) && !isset($db_qs[$new])) {
                $db_qs[$new] = $db_qs[$old] + ['_rename' => true];
                unset($db_qs[$old]);
            }
        }

        // uuid of the file => uuid in this GLPI (existing items by name, new items get a fresh uuid)
        $map = [];
        $fresh = static function () use ($DB): string {
            do {
                $u = \Ramsey\Uuid\Uuid::uuid4()->toString();
            } while (countElementsInTable('glpi_forms_questions', ['uuid' => $u]) || countElementsInTable('glpi_forms_sections', ['uuid' => $u]));
            return $u;
        };
        $json_sec_name = [];
        foreach ($json['sections'] as $s) {
            $json_sec_name[$s['id']] = $s['name'];
            $map[$s['uuid']] = isset($db_secs[$s['name']]) ? $db_secs[$s['name']]['uuid'] : $fresh();
        }
        foreach ($json['questions'] as $q) {
            $map[$q['uuid']] = isset($db_qs[$q['name']]) ? $db_qs[$q['name']]['uuid'] : $fresh();
        }
        $conds = static function (array $list) use ($map): string {
            $out = [];
            foreach ($list as $c) {
                $u = $map[$c['item_uuid']] ?? $c['item_uuid'];
                $out[] = ['item' => $c['item_type'] . '-' . $u, 'item_uuid' => $u, 'item_type' => $c['item_type'],
                    'value_operator' => $c['value_operator'], 'value' => $c['value'], 'logic_operator' => $c['logic_operator']];
            }
            return json_encode($out);
        };

        // sections: add the missing ones, follow the file's order
        foreach ($json['sections'] as $s) {
            if (isset($db_secs[$s['name']])) {
                if ((int) $db_secs[$s['name']]['rank'] !== (int) $s['rank']) {
                    $DB->update('glpi_forms_sections', ['rank' => (int) $s['rank']], ['id' => $db_secs[$s['name']]['id']]);
                }
                continue;
            }
            $DB->insert('glpi_forms_sections', ['uuid' => $map[$s['uuid']], 'forms_forms_id' => $form_id, 'name' => $s['name'],
                'description' => $s['description'], 'rank' => (int) $s['rank'],
                'visibility_strategy' => $s['visibility_strategy'], 'conditions' => $conds($s['conditions'] ?? [])]);
            $db_secs[$s['name']] = ['id' => $DB->insertId(), 'uuid' => $map[$s['uuid']]];
            $n++;
        }
        // questions: add the missing ones, move/rename the renamed ones
        foreach ($json['questions'] as $q) {
            $sec = $db_secs[$json_sec_name[$q['section_id']] ?? ''] ?? null;
            if ($sec === null) {
                continue;
            }
            $row = ['forms_sections_id' => (int) $sec['id'], 'forms_sections_uuid' => $sec['uuid'], 'name' => $q['name'],
                'is_mandatory' => (int) $q['is_mandatory'], 'vertical_rank' => (int) $q['vertical_rank'],
                'horizontal_rank' => $q['horizontal_rank'], 'description' => $q['description']];
            if (isset($db_qs[$q['name']])) {
                if (!empty($db_qs[$q['name']]['_rename'])) {
                    $DB->update('glpi_forms_questions', $row, ['id' => $db_qs[$q['name']]['id']]);
                    $n++;
                }
                continue;
            }
            $DB->insert('glpi_forms_questions', $row + ['uuid' => $map[$q['uuid']], 'type' => $q['type'],
                'default_value' => $q['default_value'], 'extra_data' => $q['extra_data'] === null ? null : json_encode($q['extra_data']),
                'visibility_strategy' => $q['visibility_strategy'], 'conditions' => $conds($q['conditions'] ?? []),
                'validation_strategy' => $q['validation_strategy'] ?? 'no_validation', 'validation_conditions' => '[]']);
            $n++;
        }
        return $n;
    }

    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:projectreports:import-forms');
        $this->setDescription('Import the capacity request forms (New project, Expansion, Modernization, Release resources) into Administration > Forms.');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Login used in place of users the forms name but this GLPI does not have');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var DBmysql $DB */
        global $DB;

        $files = glob(dirname(__DIR__) . '/extras/forms/capacity-request-*.json') ?: [];
        if (!$files) {
            $output->writeln('<error>No form files found in extras/forms.</error>');
            return 1;
        }

        // the user that stands in for users this GLPI does not have
        $login = (string) $input->getOption('user');
        $fallback = null;
        if ($login !== '') {
            $u = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login], 'LIMIT' => 1])->current();
            if (!$u) {
                $output->writeln(sprintf('<error>User "%s" not found.</error>', $login));
                return 1;
            }
            $fallback = (int) $u['id'];
        } else {
            $u = $DB->request([
                'SELECT' => 'u.id',
                'FROM' => 'glpi_users AS u',
                'INNER JOIN' => [
                    'glpi_profiles_users AS pu' => ['ON' => ['pu' => 'users_id', 'u' => 'id']],
                    'glpi_profiles AS p' => ['ON' => ['p' => 'id', 'pu' => 'profiles_id']],
                ],
                'WHERE' => ['p.name' => 'Super-Admin', 'u.is_active' => 1, 'u.is_deleted' => 0],
                'ORDER' => 'u.id',
                'LIMIT' => 1,
            ])->current();
            $fallback = $u ? (int) $u['id'] : null;
        }

        $entities = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_entities'])), 'id');
        $serializer = new FormSerializer();
        $imported = $skipped = $failed = 0;

        foreach ($files as $file) {
            $json = (string) file_get_contents($file);
            $spec = json_decode($json, true);
            $name = (string) ($spec['forms'][0]['name'] ?? basename($file));

            if ($DB->request(['FROM' => 'glpi_forms_forms', 'WHERE' => ['name' => $name, 'is_deleted' => 0], 'LIMIT' => 1])->count()) {
                $output->writeln(sprintf('Already there: %s', $name));
                $skipped++;
                continue;
            }

            $mapper = new DatabaseMapper(array_map('intval', $entities));
            foreach ($spec['forms'] ?? [] as $form) {
                foreach ($form['data_requirements'] ?? [] as $req) {
                    if (($req['itemtype'] ?? '') !== 'User' || $fallback === null) {
                        continue;
                    }
                    $found = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => $req['name']], 'LIMIT' => 1])->current();
                    $mapper->addMappedItem('User', $req['name'], $found ? (int) $found['id'] : $fallback);
                }
            }

            try {
                $result = $serializer->importFormsFromJson($json, $mapper);
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>Not imported: %s (%s)</error>', $name, $e->getMessage()));
                $failed++;
                continue;
            }
            foreach ($result->getImportedForms() as $form) {
                $output->writeln(sprintf('<info>Imported: %s</info>', $form->fields['name'] ?? $name));
                $imported++;
            }
            foreach ($result->getFailedFormImports() as $fname => $error) {
                $output->writeln(sprintf('<error>Not imported: %s (%s)</error>', $fname, $error instanceof \UnitEnum ? $error->name : (string) $error));
                $failed++;
            }
        }

        $updated = self::updateExisting();
        $output->writeln(sprintf('Forms imported: %d, already there: %d, failed: %d, settings updated: %d', $imported, $skipped, $failed, $updated));
        return $failed ? 1 : 0;
    }
}
