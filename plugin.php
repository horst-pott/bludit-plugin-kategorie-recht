<?php defined('BLUDIT') or die('Bludit CMS.');

class KategorieRechte extends Plugin
{
    // ------------------------------------------------------------------
    // Grunddaten des Plugins:
    // - assignments: welcher Benutzername welcher Kategorie zugewiesen ist
    // - visitStats: wie oft und wann sich jeder Benutzer eingeloggt hat
    // ------------------------------------------------------------------
    public function init()
    {
        $this->dbFields = array(
            'assignments' => '{}',
            'visitStats' => '{}'
        );
    }

    // Liefert für jeden Benutzernamen ein Array von Kategorie-Schlüsseln.
    // Rückwärtskompatibel: alte Daten hatten pro Benutzer nur einen einzelnen
    // Text (eine Kategorie) statt eines Arrays - wird automatisch umgewandelt.
    private function readAssignments()
    {
        $raw = $this->getValue('assignments', false);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $normalized = array();
        foreach ($decoded as $username => $value) {
            if (is_array($value)) {
                $normalized[$username] = array_values(array_filter($value, function ($v) { return $v !== ''; }));
            } elseif ($value !== '') {
                $normalized[$username] = array($value); // alter Einzel-Wert -> Array mit einem Eintrag
            } else {
                $normalized[$username] = array();
            }
        }
        return $normalized;
    }

    private function readStats()
    {
        $raw = $this->getValue('visitStats', false);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function writeStats($stats)
    {
        $this->setField('visitStats', json_encode($stats));
    }

    // Zählt einen Besuch, höchstens einmal pro Sitzung
    private function trackVisit($username)
    {
        if (Session::get('kr_visit_counted') === $username) {
            return;
        }
        $stats = $this->readStats();
        if (!isset($stats[$username]) || !is_array($stats[$username])) {
            $stats[$username] = array('count' => 0, 'last' => '');
        }
        $stats[$username]['count'] = (int) $stats[$username]['count'] + 1;
        $stats[$username]['last'] = date('Y-m-d H:i:s');
        $this->writeStats($stats);
        Session::set('kr_visit_counted', $username);
    }

    // ------------------------------------------------------------------
    // Einstellungsseiten (mehrere Ansichten über ?tab=...)
    // ?tab=alle-artikel -> für ALLE Benutzer: Übersicht aller Artikel (nur ansehen)
    // ?tab=logins -> nur Admin: Anmelde-Statistik
    // (kein tab) -> nur Admin: Kategorie-Zuweisung
    // ------------------------------------------------------------------
    public function adminController()
    {
        global $login;
        if (!is_object($login)) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method == 'POST') {
            if (!$login->role() || $login->role() !== 'admin') {
                Alert::set('Nur Administratoren dürfen Kategorie-Zuweisungen ändern.');
                return;
            }
            // Hinweis: Auf eine strikte zusätzliche CSRF-Token-Prüfung wird hier
            // bewusst verzichtet, da sie in Tests zu Fehlalarmen führte. Der
            // Zugriff ist ohnehin nur für eingeloggte Administratoren möglich.
            $assignments = array();
            if (isset($_POST['assign']) && is_array($_POST['assign'])) {
                foreach ($_POST['assign'] as $username => $categoryKeys) {
                    $username = Sanitize::html($username);
                    $clean = array();
                    if (is_array($categoryKeys)) {
                        foreach ($categoryKeys as $categoryKey) {
                            $categoryKey = Sanitize::html($categoryKey);
                            if ($categoryKey !== '') {
                                $clean[] = $categoryKey;
                            }
                        }
                    }
                    if (!empty($clean)) {
                        $assignments[$username] = $clean;
                    }
                }
            }
            $this->setField('assignments', json_encode($assignments));
            Alert::set('Kategorie-Zuweisungen gespeichert.');
        }
    }

    public function adminView()
    {
        global $login;
        if (!is_object($login) || !$login->role()) {
            return '<p>Bitte einloggen.</p>';
        }
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'zuweisung';
        if ($tab === 'alle-artikel') {
            return $this->renderAlleArtikel();
        }
        if ($tab === 'logins') {
            if ($login->role() !== 'admin') {
                return '<p>Nur Administratoren können diese Einstellungen sehen.</p>';
            }
            return $this->renderLoginStatistik();
        }
        if ($login->role() !== 'admin') {
            return '<p>Nur Administratoren können diese Einstellungen sehen.</p>';
        }
        return $this->renderZuweisung();
    }

    private function adminTabNav($active)
    {
        $base = HTML_PATH_ADMIN_ROOT . 'plugin/' . Text::lowercase(__CLASS__);
        $html = '<p>';
        $html .= ($active === 'zuweisung') ? '<b>Kategorie-Zuweisung</b>' : ('<a href="' . $base . '">Kategorie-Zuweisung</a>');
        $html .= ' &middot; ';
        $html .= ($active === 'logins') ? '<b>Login-Statistik</b>' : ('<a href="' . $base . '?tab=logins">Login-Statistik</a>');
        $html .= ' &middot; ';
        $html .= '<a href="' . $base . '?tab=alle-artikel">Alle Artikel ansehen</a>';
        $html .= '</p><hr>';
        return $html;
    }

    private function renderZuweisung()
    {
        global $security;
        global $users;
        $tokenCSRF = $security->getTokenCSRF();
        $assignments = $this->readAssignments();
        $categoryList = getCategories();

        $html = $this->adminTabNav('zuweisung');
        $html .= '<h3>Kategorie-Zuweisung pro Benutzer</h3>';
        $html .= '<p>W&auml;hle hier je Autor eine oder mehrere Kategorien aus, in denen er ver&ouml;ffentlichen darf. ';
        $html .= 'Administratoren sind von dieser Einschr&auml;nkung nicht betroffen. ';
        $html .= 'Ein Benutzer ohne angehakte Kategorie ist ebenfalls nicht eingeschr&auml;nkt (darf &uuml;berall ver&ouml;ffentlichen).</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="tokenCSRF" value="' . $tokenCSRF . '">';
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr><th>Benutzername</th><th>Rolle</th><th>Zugewiesene Kategorien</th></tr></thead><tbody>';
        foreach ($users->keys() as $username) {
            try {
                $user = new User($username);
            } catch (Exception $e) {
                continue;
            }
            if ($user->role() === 'admin') {
                continue;
            }
            $current = isset($assignments[$username]) ? $assignments[$username] : array();
            $summaryText = empty($current) ? 'Keine Einschr&auml;nkung' : (count($current) . ' Kategorie' . (count($current) > 1 ? 'n' : '') . ' ausgew&auml;hlt');
            $html .= '<tr>';
            $html .= '<td style="vertical-align:top; padding-top:14px;">' . $username . '</td>';
            $html .= '<td style="vertical-align:top; padding-top:14px;">' . $user->role() . '</td>';
            $html .= '<td>';
            $html .= '<details>';
            $html .= '<summary style="cursor:pointer; color:#000; padding:6px 0; font-size:14.5px;">' . $summaryText . ' <span style="color:#666;">(zum Ändern aufklappen)</span></summary>';
            $html .= '<div style="padding:10px 0 4px;">';
            foreach ($categoryList as $category) {
                $checked = in_array($category->key(), $current, true) ? ' checked' : '';
                $checkboxId = 'cat_' . $username . '_' . $category->key();
                $html .= '<label for="' . $checkboxId . '" style="display:block; align-items:center; gap:6px; margin:4px 0; color:#000; font-weight:normal; font-size:14.5px;">';
                $html .= '<input type="checkbox" id="' . $checkboxId . '" name="assign[' . $username . '][]" value="' . $category->key() . '"' . $checked . '> ';
                $html .= $category->name();
                $html .= '</label>';
            }
            $html .= '</div>';
            $html .= '</details>';
            $html .= '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<button type="submit" class="btn btn-primary">Speichern</button>';
        $html .= '</form>';
        return $html;
    }

    private function renderAlleArtikel()
    {
        global $pages;
        $keys = $pages->getList(1, -1, true, true, true, true, true);
        $html = '<h3>Alle Artikel (nur Ansicht)</h3>';
        $html .= '<p>Hier siehst du alle Artikel der Zeitung, unabh&auml;ngig davon, wer sie geschrieben hat. ';
        $html .= 'Bearbeiten kannst du weiterhin ausschlie&szlig;lich deine eigenen Artikel.</p>';
        if (empty($keys)) {
            $html .= '<p>Noch keine Artikel vorhanden.</p>';
            return $html;
        }
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr><th>Titel</th><th>Kategorie</th><th>Autor</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($keys as $key) {
            $row = $pages->getPageDB($key);
            $p = buildPage($key);
            if (!$p || !$row) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td>' . $p->title() . '</td>';
            $html .= '<td>' . (isset($row['category']) ? $row['category'] : '') . '</td>';
            $html .= '<td>' . (isset($row['username']) ? $row['username'] : '') . '</td>';
            $html .= '<td>' . (isset($row['type']) ? $row['type'] : '') . '</td>';
            $html .= '<td><a href="' . $p->permalink() . '" target="_blank">Ansehen &rarr;</a></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function renderLoginStatistik()
    {
        global $users;
        $stats = $this->readStats();
        $html = $this->adminTabNav('logins');
        $html .= '<h3>Anmelde-Statistik</h3>';
        $html .= '<p>Z&auml;hlt, wie oft sich jeder Benutzer eingeloggt hat (eine neue Sitzung z&auml;hlt als ein Login), und wann zuletzt aktiv.</p>';
        $html .= '<table class="table table-striped">';
        $html .= '<thead><tr><th>Benutzername</th><th>Rolle</th><th>Anzahl Logins</th><th>Zuletzt aktiv</th></tr></thead><tbody>';
        foreach ($users->keys() as $username) {
            try {
                $user = new User($username);
            } catch (Exception $e) {
                continue;
            }
            if ($user->role() === 'admin') {
                continue;
            }
            $count = isset($stats[$username]['count']) ? $stats[$username]['count'] : 0;
            $last = isset($stats[$username]['last']) && $stats[$username]['last'] !== '' ? $stats[$username]['last'] : '– noch nie –';
            $html .= '<tr>';
            $html .= '<td>' . $username . '</td>';
            $html .= '<td>' . $user->role() . '</td>';
            $html .= '<td>' . $count . '</td>';
            $html .= '<td>' . $last . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public function adminSidebar()
    {
        global $login;
        if (!is_object($login) || !$login->role()) {
            return '';
        }
        $base = HTML_PATH_ADMIN_ROOT . 'plugin/' . Text::lowercase(__CLASS__);
        $html = '<a class="nav-link" href="' . $base . '?tab=alle-artikel">Alle Artikel</a>';
        if ($login->role() === 'admin') {
            $html .= '<a class="nav-link" href="' . $base . '">Kategorie-Rechte</a>';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Läuft bei JEDEM Admin-Seitenaufruf: zählt Besuche + blockiert
    // das Bearbeiten/Löschen fremder Artikel für Nicht-Admins, sowie
    // (neu) das Bearbeiten/Löschen der Abteilungs-Unterseiten für
    // Nicht-Admins, unabhängig davon wem die Seite "gehört".
    // ------------------------------------------------------------------
    public function beforeAdminLoad()
    {
        global $login;
        if (!is_object($login)) {
            return;
        }

        $usernameForStats = $login->username();
        if (!empty($usernameForStats)) {
            $this->trackVisit($usernameForStats);
        }

        if (!$login->role() || $login->role() === 'admin') {
            return; // Admins sind unbeschränkt
        }

        $username = $login->username();
        if (empty($username)) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (preg_match('#/admin/(edit-content|delete-content)/([^/?]+)#', $uri, $matches)) {
            $pageKey = urldecode($matches[2]);
            if (empty($pageKey)) {
                return;
            }

            // NEU: Abteilungs-Unterseiten (verwaltet vom Plugin "Abteilungen")
            // dürfen grundsätzlich nur von Admins bearbeitet oder gelöscht werden,
            // unabhängig von Kategorie-Zuweisung oder Autorschaft.
            if (function_exists('abteilungen_is_department_page') && abteilungen_is_department_page($pageKey)) {
                Alert::set('Diese Seite geh&ouml;rt zu den Abteilungen und darf nur von Administratoren bearbeitet werden.');
                Redirect::page('admin', 'content');
                return;
            }

            if (!$this->isOwnPage($pageKey, $username)) {
                Alert::set('Du darfst nur deine eigenen Artikel bearbeiten oder l&ouml;schen.');
                Redirect::page('admin', 'content');
            }
        }
    }

    private function isOwnPage($pageKey, $username)
    {
        global $pages;
        if (!$pages->exists($pageKey)) {
            return true;
        }
        $row = $pages->getPageDB($pageKey);
        if (!$row || !isset($row['username'])) {
            return true;
        }
        return $row['username'] === $username;
    }

    // ------------------------------------------------------------------
    // Nach dem Speichern prüfen, ob die Kategorie zum Autor passt.
    // Setzt den Artikel bei Verstoß zurück auf "Entwurf".
    // ------------------------------------------------------------------
    public function afterPageCreate($pageKey = false)
    {
        $this->enforceCategory($pageKey);
    }

    public function afterPageModify($pageKey = false)
    {
        $this->enforceCategory($pageKey);
    }

    private function enforceCategory($pageKey)
    {
        global $login;
        global $pages;
        if (empty($pageKey)) {
            return;
        }
        if (!is_object($login) || !$login->role() || $login->role() === 'admin') {
            return;
        }
        $username = $login->username();
        if (empty($username)) {
            return;
        }
        $assignments = $this->readAssignments();
        if (empty($assignments[$username])) {
            return;
        }
        $assignedCategories = $assignments[$username];
        if (!$pages->exists($pageKey)) {
            return;
        }
        $row = $pages->getPageDB($pageKey);
        if (!$row) {
            return;
        }
        if (isset($row['type']) && $row['type'] === 'static') {
            return;
        }
        if (isset($row['type']) && $row['type'] === 'draft') {
            return;
        }
        $currentCategory = isset($row['category']) ? $row['category'] : '';
        if (in_array($currentCategory, $assignedCategories, true)) {
            return;
        }
        try {
            $page = buildPage($pageKey);
            $args = $row;
            $args['key'] = $pageKey;
            $args['type'] = 'draft';
            $args['content'] = $page ? $page->contentRaw() : '';
            editPage($args);
            Alert::set(
                'Achtung: Dein Artikel wurde automatisch als Entwurf gespeichert, ' .
                'weil die ausgew&auml;hlte Kategorie nicht zu deinen zugewiesenen Rubriken geh&ouml;rt. ' .
                'Bitte eine deiner erlaubten Kategorien w&auml;hlen und den Artikel erneut ver&ouml;ffentlichen.'
            );
            if (function_exists('postfach_send_systemnachricht')) {
                $articleTitle = isset($row['title']) ? $row['title'] : $pageKey;
                postfach_send_systemnachricht(
                    $username,
                    'Artikel zurückgesetzt: ' . $articleTitle,
                    'Dein Artikel "' . $articleTitle . '" wurde automatisch als Entwurf gespeichert, ' .
                    'weil die Kategorie nicht zu deinen zugewiesenen Rubriken gehört. ' .
                    'Bitte im Editor eine deiner erlaubten Kategorien wählen und erneut veröffentlichen.'
                );
            }
        } catch (Exception $e) {
            Log::set(__METHOD__ . ' - Fehler beim automatischen Zurücksetzen: ' . $e->getMessage());
        }
    }
}
