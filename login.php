<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/AdminAuth.php';
SvAmazonReturnsAdminAuth::start();
$returnTo=(string)($_POST['return_to']??$_GET['return_to']??'/admin/amazon-returns/');
if(!str_starts_with($returnTo,'/admin/amazon-returns')||str_contains($returnTo,"\r")||str_contains($returnTo,"\n"))$returnTo='/admin/amazon-returns/';
if (SvAmazonReturnsAdminAuth::loggedIn()) { header('Location: '.$returnTo, true, 302); exit; }
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (SvAmazonReturnsAdminAuth::login($username, $password)) {
        header('Location: '.$returnTo, true, 302); exit;
    }
    usleep(250000);
    $error = 'Credenciais inválidas.';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Amazon Returns</title>
<style>body{font-family:system-ui;background:#f4f6f8;margin:0;display:grid;place-items:center;min-height:100vh}.box{width:min(420px,92vw);background:#fff;padding:28px;border:1px solid #ddd;border-radius:14px}label{display:block;font-weight:700;margin:14px 0 6px}input,button{width:100%;box-sizing:border-box;padding:12px;border-radius:9px;border:1px solid #bbb;font:inherit}button{margin-top:18px;background:#17202a;color:#fff;font-weight:800}.err{color:#b42318}.secondary{background:#fff;color:#17202a;margin-top:8px}</style></head><body>
<form class="box" method="post"><h1>Devoluções Amazon</h1><p>Acesso interno Vivaliz. A sessão é protegida e expira após inatividade.</p><input type="hidden" name="return_to" value="<?=htmlspecialchars($returnTo,ENT_QUOTES,'UTF-8')?>"><?php if($error!==''):?><p class="err"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></p><?php endif;?>
<label for="username">Usuário</label><input id="username" name="username" autocomplete="username" required><label for="password">Senha</label><input id="password" name="password" type="password" autocomplete="current-password" required><button type="button" id="toggle-password" class="secondary">Mostrar senha</button><button>Entrar</button></form>
<script>const p=document.querySelector('#password'),b=document.querySelector('#toggle-password');b?.addEventListener('click',()=>{const show=p.type==='password';p.type=show?'text':'password';b.textContent=show?'Ocultar senha':'Mostrar senha';});</script></body></html>
