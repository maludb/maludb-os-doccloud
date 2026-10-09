<?php
/**
 * Proof: the application switcher as the Phase 2 shell carries it (K31; design-system "Header: the application switcher"; the
 * integration plugin's sign-on-and-directory.md §8). Under this harness there is no kernel to ask, so the header shows nothing and the
 * old "All applications" icon is gone; the partial itself is proven from a fixture of the kernel's os.my-applications/1 document.
 * Run through tests/phase2/run.sh (needs the servers it starts).
 */
require __DIR__ . '/lib.php';
$root = dirname(__DIR__, 2);
echo "1. The shell\n";
[$jAdmin, ] = sign_on(1);
$b = page($jAdmin, '/')['body'];
ok(!str_contains($b, 'header-launcher-link'), 'the ad-hoc All applications icon is gone from the header');
ok(!str_contains($b, 'header-apps-toggle') && !str_contains($b, 'header-helpdesk-btn'), 'with no kernel to ask, the header shows neither the button nor the menu');
ok(str_contains(file_get_contents($root . '/app/views/layout.php'), "view('shared/app-switcher.php')"), 'the layout renders shared/app-switcher.php');
ok(str_contains(file_get_contents($root . '/app/bootstrap.php'), "require_once __DIR__ . '/switcher.php';"), 'the bootstrap requires app/switcher.php');
ok(str_contains(file_get_contents($root . '/html/assets/css/app-overrides.css'), 'APPLICATION SWITCHER'), 'app-overrides.css has the APPLICATION SWITCHER section');
echo "2. The partial, from a fixture\n";
$feed = ['schema' => 'os.my-applications/1', 'launcher_url' => 'https://app.example.test/', 'os_url' => 'https://os.example.test', 'applications' => [
    ['id' => 57, 'key' => 'helpdesk', 'name' => 'Help Desk', 'icon' => 'feather-life-buoy', 'current' => false, 'launch_path' => '/launch/57', 'scopes' => []],
    ['id' => 70, 'key' => 'doccloud', 'name' => 'DocCloud', 'icon' => 'feather-hard-drive', 'current' => true, 'launch_path' => '/launch/70', 'scopes' => []],
    ['id' => 56, 'key' => 'txtschedules', 'name' => 'txtSchedules', 'icon' => 'feather-calendar', 'current' => false, 'launch_path' => '/launch/56',
     'scopes' => [['id' => 3, 'name' => 'Downtown', 'role' => 'Manager', 'launch_path' => '/launch/56?scope=3']]],
]];
$render = static function (array $feed) use ($root): string {
    $f = tempnam(sys_get_temp_dir(), 'sw') . '.php';
    file_put_contents($f, '<?php putenv("OS_LAUNCHER_URL=https://app.example.test/"); putenv("OS_APPLICATION_TOKEN=osapp_fixture"); require ' . var_export($root . '/app/bootstrap.php', true) . '; echo view("shared/app-switcher.php", ["feed" => json_decode(' . var_export(json_encode($feed), true) . ', true)]);');
    $out = (string) shell_exec('php ' . escapeshellarg($f) . ' 2>&1'); unlink($f); return $out;
};
$h = $render($feed);
ok(str_contains($h, 'id="header-helpdesk-btn"') && str_contains($h, 'href="https://app.example.test/launch/57"'), 'the Helpdesk button links to the launcher\'s /launch/57');
ok(str_contains($h, '<span class="dropdown-item active" id="header-apps-item-70"'), 'this application is marked current and not linked');
ok(str_contains($h, 'id="header-apps-item-56-3"') && str_contains($h, 'https://app.example.test/launch/56?scope=3') && str_contains($h, 'Downtown'), 'a scoped application has one row per scope with ?scope=');
ok(str_contains($h, 'id="header-apps-launcher"') && str_contains($h, 'id="header-apps-os"'), 'the foot links to the launcher and, for a super-admin, the operating system');
ok(!preg_match('/hx-(get|post)=/', $h), 'no link is an HTMX swap');
$asHd = $feed; foreach ($asHd['applications'] as $i => $a) { $asHd['applications'][$i]['current'] = $a['key'] === 'helpdesk'; }
ok(!str_contains($render($asHd), 'header-helpdesk-btn'), 'inside the Help Desk the button is not shown');
ok(trim($render(['applications' => []])) === '', 'an empty feed renders nothing');
finish();
