<?php

/**
 * Capacity request forms shipped with the plugin (extras/forms/capacity-request-*.json).
 * On install they are imported into Administration › Forms, the same way as GLPI's Import screen does.
 * A form that already exists with the same name (GLPI gives imported forms a new UUID, so the name is what identifies
 * them) is left untouched, so reinstalling or updating the plugin never duplicates a form or overwrites changes made
 * in GLPI. Uninstalling the plugin keeps the forms and their answers.
 */
class PluginProjectreportsForms
{
    public const PATTERN = 'capacity-request-*.json';

    /**
     * Import the bundled forms that are not in GLPI yet.
     *
     * @return array{imported: string[], skipped: string[], failed: string[]}
     */
    public static function install(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = ['imported' => [], 'skipped' => [], 'failed' => []];
        if (!$DB->tableExists('glpi_forms_forms')) {
            return $result;
        }

        $files = glob(dirname(__DIR__) . '/extras/forms/' . self::PATTERN) ?: [];
        sort($files);
        $serializer = new Glpi\Form\Export\Serializer\FormSerializer();

        foreach ($files as $file) {
            $json = (string) file_get_contents($file);
            $spec = json_decode($json, true);
            $form = $spec['forms'][0] ?? null;
            if (!is_array($form) || empty($form['name'])) {
                $result['failed'][] = basename($file);
                continue;
            }
            $name = (string) ($form['name'] ?? basename($file));
            if (countElementsInTable('glpi_forms_forms', ['name' => $name]) > 0) {
                $result['skipped'][] = $name;
                continue;
            }

            try {
                $mapper = new Glpi\Form\Export\Context\DatabaseMapper(self::entities());
                self::mapMissingUsers($mapper, (array) ($form['data_requirements'] ?? []));
                $import = $serializer->importFormsFromJson($json, $mapper);
                if (count($import->getImportedForms()) > 0) {
                    $result['imported'][] = $name;
                } else {
                    $result['failed'][] = $name;
                }
            } catch (Throwable $e) {
                trigger_error('Project reports: form "' . $name . '" not imported: ' . $e->getMessage(), E_USER_WARNING);
                $result['failed'][] = $name;
            }
        }

        return $result;
    }

    /** Entities the forms may be attached to: the current user's, or the root entity when installing from the CLI. */
    private static function entities(): array
    {
        $entities = Session::getActiveEntities();
        return $entities !== [] ? $entities : [0];
    }

    /**
     * The exported forms name the users of the server they came from (e.g. "admin" as the default assignee).
     * Users with the same login are used as they are; a user that does not exist here is replaced by the user
     * running the install (or the first super-admin), so the import never stops on a missing user.
     */
    private static function mapMissingUsers(Glpi\Form\Export\Context\DatabaseMapper $mapper, array $requirements): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $fallback = (int) Session::getLoginUserID();
        if ($fallback <= 0) {
            $row = $DB->request([
                'SELECT' => 'glpi_users.id',
                'FROM' => 'glpi_users',
                'INNER JOIN' => ['glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']]],
                'WHERE' => ['glpi_profiles_users.profiles_id' => 4, 'glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0],
                'ORDER' => 'glpi_users.id',
                'LIMIT' => 1,
            ])->current();
            $fallback = (int) ($row['id'] ?? 0);
        }

        foreach ($requirements as $req) {
            if (($req['itemtype'] ?? '') !== User::class) {
                continue;
            }
            $login = (string) ($req['name'] ?? '');
            $user = new User();
            if ($login !== '' && $user->getFromDBbyName($login)) {
                $mapper->addMappedItem(User::class, $login, (int) $user->getID());
            } elseif ($fallback > 0) {
                $mapper->addMappedItem(User::class, $login, $fallback);
            }
        }
    }
}
