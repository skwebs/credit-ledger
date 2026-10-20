<?php
declare(strict_types=1);
// Card Ledger API — PHP 8.1+, PDO SQLite
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata'); // Change here if the account uses another local timezone.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
$appDir = dirname($scriptDir);
$appCookiePath = '/' . trim($appDir, '/') . '/';
if ($appCookiePath === '//') $appCookiePath = '/';
ini_set('session.gc_maxlifetime', (string)(30 * 24 * 60 * 60));
session_set_cookie_params(['httponly'=>true, 'secure'=>$https, 'samesite'=>'Lax', 'path'=>'/']);
session_start();

const DEFAULT_DB_PATH = __DIR__ . '/../storage/app.sqlite';
function dbPath(): string { $configured=getenv('CCM_DB_PATH'); return $configured && trim($configured)!=='' ? $configured : DEFAULT_DB_PATH; }
function rememberCookieOptions(int $expires): array { global $https, $appCookiePath; return ['expires'=>$expires,'path'=>$appCookiePath,'secure'=>$https,'httponly'=>true,'samesite'=>'Lax']; }
function clearRememberCookie(): void { setcookie('ccm_remember','',rememberCookieOptions(time()-3600)); }
function issueRememberToken(PDO $pdo): void {
  if (!empty($_COOKIE['ccm_remember']) && preg_match('/^[a-f0-9]{64}$/',(string)$_COOKIE['ccm_remember'])) { $old=$pdo->prepare('DELETE FROM remember_tokens WHERE token_hash=?'); $old->execute([hash('sha256',(string)$_COOKIE['ccm_remember'])]); }
  $expired=$pdo->prepare('DELETE FROM remember_tokens WHERE expires_at <= ?'); $expired->execute([(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
  $raw=bin2hex(random_bytes(32)); $expires=(new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
  $q=$pdo->prepare('INSERT INTO remember_tokens(token_hash,expires_at) VALUES(?,?)'); $q->execute([hash('sha256',$raw),$expires]);
  setcookie('ccm_remember',$raw,rememberCookieOptions(time()+30*24*60*60));
}
function restoreRememberSession(PDO $pdo): void {
  if (!empty($_SESSION['user_id']) || empty($_COOKIE['ccm_remember'])) return;
  $raw=(string)$_COOKIE['ccm_remember'];
  if (!preg_match('/^[a-f0-9]{64}$/',$raw)) { clearRememberCookie(); return; }
  $q=$pdo->prepare('SELECT id FROM remember_tokens WHERE token_hash=? AND expires_at>?'); $q->execute([hash('sha256',$raw),(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
  if (!$q->fetchColumn()) { clearRememberCookie(); return; }
  $user=$pdo->query('SELECT id,username FROM app_user WHERE id=1')->fetch();
  if (!$user) { clearRememberCookie(); return; }
  session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; $_SESSION['username']=$user['username']; $_SESSION['csrf']=bin2hex(random_bytes(24));
  issueRememberToken($pdo); // Rotate the persistent token after restoring a remembered session.
}
function out($data, int $status=200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail(string $message, int $status=400): never { out(['error'=>$message], $status); }
function body(): array { $v=json_decode(file_get_contents('php://input') ?: '[]', true); return is_array($v)?$v:[]; }
function db(): PDO {
  static $pdo=null; if ($pdo instanceof PDO) return $pdo;
  $path=dbPath(); $dir=dirname($path); if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Database directory could not be created.');
  $pdo=new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA busy_timeout=5000');
  $pdo->exec("CREATE TABLE IF NOT EXISTS app_user (id INTEGER PRIMARY KEY CHECK(id=1), username TEXT NOT NULL, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS credit_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, bank_name TEXT NOT NULL, last4 TEXT NOT NULL DEFAULT '', credit_limit_minor INTEGER, billing_day INTEGER NOT NULL DEFAULT 5 CHECK(billing_day BETWEEN 1 AND 31), billing_rule TEXT NOT NULL DEFAULT 'end' CHECK(billing_rule IN ('end','start')), due_days INTEGER NOT NULL DEFAULT 20, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS upi_apps (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL COLLATE NOCASE UNIQUE, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER NOT NULL REFERENCES credit_cards(id), particular TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', amount_minor INTEGER NOT NULL CHECK(amount_minor>0), txn_at TEXT NOT NULL, settlement_date TEXT NOT NULL, payment_method TEXT NOT NULL CHECK(payment_method IN ('swipe','online','upi')), upi_app_id INTEGER REFERENCES upi_apps(id) ON DELETE SET NULL, upi_app_name TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', attachment_path TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $transactionColumns=$pdo->query('PRAGMA table_info(transactions)')->fetchAll();
  $transactionColumnNames=array_column($transactionColumns,'name');
  if (!in_array('attachment_path',$transactionColumnNames,true)) $pdo->exec("ALTER TABLE transactions ADD COLUMN attachment_path TEXT NOT NULL DEFAULT ''");
  if (!in_array('category',$transactionColumnNames,true)) $pdo->exec("ALTER TABLE transactions ADD COLUMN category TEXT NOT NULL DEFAULT ''");
  if (!in_array('tags',$transactionColumnNames,true)) $pdo->exec("ALTER TABLE transactions ADD COLUMN tags TEXT NOT NULL DEFAULT ''");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_txn_settlement ON transactions(card_id, settlement_date)"); $pdo->exec("CREATE INDEX IF NOT EXISTS idx_txn_time ON transactions(txn_at DESC)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS statements (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER NOT NULL REFERENCES credit_cards(id), cycle_start TEXT NOT NULL, cycle_end TEXT NOT NULL, statement_date TEXT NOT NULL, due_date TEXT NOT NULL, amount_minor INTEGER NOT NULL DEFAULT 0, bank_amount_minor INTEGER, confirmed_at TEXT, is_locked INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(card_id,cycle_start,cycle_end))");
  $statementColumns=$pdo->query('PRAGMA table_info(statements)')->fetchAll(); $statementColumnNames=array_column($statementColumns,'name');
  if (!in_array('bank_amount_minor',$statementColumnNames,true)) $pdo->exec("ALTER TABLE statements ADD COLUMN bank_amount_minor INTEGER");
  if (!in_array('confirmed_at',$statementColumnNames,true)) $pdo->exec("ALTER TABLE statements ADD COLUMN confirmed_at TEXT");
  if (!in_array('is_locked',$statementColumnNames,true)) $pdo->exec("ALTER TABLE statements ADD COLUMN is_locked INTEGER NOT NULL DEFAULT 0");
  $pdo->exec("CREATE TABLE IF NOT EXISTS bill_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER NOT NULL REFERENCES credit_cards(id), amount_minor INTEGER NOT NULL CHECK(amount_minor>0), paid_at TEXT NOT NULL, reference TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS payment_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL REFERENCES bill_payments(id) ON DELETE CASCADE, statement_id INTEGER NOT NULL REFERENCES statements(id), amount_minor INTEGER NOT NULL CHECK(amount_minor>0), UNIQUE(payment_id,statement_id))");
  return $pdo;
}
function auth(): void { if (empty($_SESSION['user_id'])) fail('Please log in again.', 401); }
function csrf(): void { $token=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''; if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) fail('Security token expired. Refresh the page and try again.', 419); }
function intv($v, string $label, int $min=0): int { if (!is_numeric($v) || (int)$v < $min) fail($label.' is invalid.'); return (int)$v; }
function dateOnly(string $s, string $label='Date'): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s); if (!$d || $d->format('Y-m-d')!==$s) fail($label.' must be YYYY-MM-DD.'); return $s; }
function validDateTime(string $s): string { try { $d=new DateTimeImmutable($s); return $d->format('Y-m-d H:i:s'); } catch(Throwable $e) { fail('Transaction date/time is invalid.'); } }
function monthDay(int $year,int $month,int $day): DateTimeImmutable { $first=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)); $last=(int)$first->format('t'); return $first->setDate($year,$month,min($day,$last)); }
function cycleFor(array $card, string $date): array {
  $d=new DateTimeImmutable($date); $y=(int)$d->format('Y'); $m=(int)$d->format('n'); $day=(int)$card['billing_day'];
  if ($card['billing_rule']==='end') {
    $end=monthDay($y,$m,$day); if ($d>$end) { $m++; if($m>12){$m=1;$y++;} $end=monthDay($y,$m,$day); }
    $endMonthFirst=new DateTimeImmutable($end->format('Y-m-01'));
    $previousMonthFirst=$endMonthFirst->modify('-1 month');
    $previousEnd=monthDay((int)$previousMonthFirst->format('Y'),(int)$previousMonthFirst->format('n'),$day);
    $start=$previousEnd->modify('+1 day');
  } else {
    $start=monthDay($y,$m,$day); if ($d<$start) { $m--; if($m<1){$m=12;$y--;} $start=monthDay($y,$m,$day); }
    $startMonthFirst=new DateTimeImmutable($start->format('Y-m-01'));
    $nextMonthFirst=$startMonthFirst->modify('+1 month');
    $next=monthDay((int)$nextMonthFirst->format('Y'),(int)$nextMonthFirst->format('n'),$day);
    $end=$next->modify('-1 day');
  }
  return ['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'statement_date'=>$end->format('Y-m-d'),'due_date'=>$end->modify('+'.max(0,(int)$card['due_days']).' days')->format('Y-m-d')];
}
function ensureStatements(PDO $pdo, ?int $cardId=null): void {
  $sql='SELECT * FROM credit_cards WHERE active=1'.($cardId?' AND id=?':''); $st=$pdo->prepare($sql); $st->execute($cardId?[$cardId]:[]); $cards=$st->fetchAll(); $today=(new DateTimeImmutable('today'))->format('Y-m-d');
  foreach($cards as $c){
    $q=$pdo->prepare('SELECT MIN(settlement_date) first_date FROM transactions WHERE card_id=?'); $q->execute([$c['id']]); $first=$q->fetchColumn(); if(!$first) continue;
    $firstCycle=cycleFor($c,(string)$first); $cursor=new DateTimeImmutable($firstCycle['start']); $guard=0;
    while($guard++<240){ $cy=cycleFor($c,$cursor->format('Y-m-d')); if($cy['end'] >= $today) break;
      $q=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM transactions WHERE card_id=? AND settlement_date BETWEEN ? AND ?'); $q->execute([$c['id'],$cy['start'],$cy['end']]); $amt=(int)$q->fetchColumn();
      if($amt>0){$ins=$pdo->prepare('INSERT OR IGNORE INTO statements(card_id,cycle_start,cycle_end,statement_date,due_date,amount_minor) VALUES(?,?,?,?,?,?)'); $ins->execute([$c['id'],$cy['start'],$cy['end'],$cy['statement_date'],$cy['due_date'],$amt]);$existing=$pdo->prepare('SELECT id,amount_minor,is_locked FROM statements WHERE card_id=? AND cycle_start=? AND cycle_end=?');$existing->execute([$c['id'],$cy['start'],$cy['end']]);$ex=$existing->fetch();if($ex && !(int)$ex['is_locked'] && (int)$ex['amount_minor']!==$amt){$u=$pdo->prepare('UPDATE statements SET amount_minor=?,confirmed_at=NULL WHERE id=?');$u->execute([$amt,$ex['id']]);}}
      $cursor=(new DateTimeImmutable($cy['end']))->modify('+1 day');
    }
  }
}
function statementRows(PDO $pdo, ?int $cardId=null): array {
  $sql="SELECT s.*, c.name card_name, c.bank_name, COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.statement_id=s.id),0) paid_minor FROM statements s JOIN credit_cards c ON c.id=s.card_id".($cardId?' WHERE s.card_id=?':'')." ORDER BY s.cycle_end DESC";
  $q=$pdo->prepare($sql); $q->execute($cardId?[$cardId]:[]); $rows=$q->fetchAll(); foreach($rows as &$r){$billAmount=(!empty($r['confirmed_at']) && $r['bank_amount_minor']!==null)?(int)$r['bank_amount_minor']:(int)$r['amount_minor'];$r['bill_amount_minor']=$billAmount;$r['outstanding_minor']=max(0,$billAmount-(int)$r['paid_minor']);$r['status']=$r['outstanding_minor']===0?'paid':((int)$r['paid_minor']>0?'partial':'unpaid');} return $rows;
}
function allocatePayment(PDO $pdo, int $paymentId, int $cardId, int $amount): void {
  $q=$pdo->prepare("SELECT s.id, MAX(0,(CASE WHEN s.confirmed_at IS NOT NULL AND s.bank_amount_minor IS NOT NULL THEN s.bank_amount_minor ELSE s.amount_minor END)-COALESCE((SELECT SUM(pa.amount_minor) FROM payment_allocations pa WHERE pa.statement_id=s.id),0)) due FROM statements s WHERE s.card_id=? ORDER BY s.cycle_end ASC"); $q->execute([$cardId]); $left=$amount;
  foreach($q as $s){if($left<=0)break;$alloc=min($left,(int)$s['due']);if($alloc>0){$i=$pdo->prepare('INSERT INTO payment_allocations(payment_id,statement_id,amount_minor) VALUES(?,?,?)');$i->execute([$paymentId,$s['id'],$alloc]);$left-=$alloc;}}
}
$pdo=null; $action=$_GET['action'] ?? ''; $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
 $pdo=db(); restoreRememberSession($pdo);
 if($action==='status' && $method==='GET') { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); out(['setup_required'=>(int)$pdo->query('SELECT COUNT(*) FROM app_user')->fetchColumn()===0,'authenticated'=>!empty($_SESSION['user_id']),'username'=>$_SESSION['username']??null,'csrf'=>$_SESSION['csrf']]); }
 if($action==='setup' && $method==='POST') { csrf(); if((int)$pdo->query('SELECT COUNT(*) FROM app_user')->fetchColumn()>0) fail('Setup has already been completed.',409); $b=body();$u=trim((string)($b['username']??''));$p=(string)($b['password']??'');if(strlen($u)<3||strlen($p)<10)fail('Username must be at least 3 characters and password at least 10 characters.');$s=$pdo->prepare('INSERT INTO app_user(id,username,password_hash) VALUES(1,?,?)');$s->execute([$u,password_hash($p,PASSWORD_DEFAULT)]);session_regenerate_id(true);$_SESSION['user_id']=1;$_SESSION['username']=$u;$_SESSION['csrf']=bin2hex(random_bytes(24));if(!empty($b['remember']))issueRememberToken($pdo);else clearRememberCookie();out(['ok'=>true,'csrf'=>$_SESSION['csrf']]); }
 if($action==='login' && $method==='POST') { csrf();$b=body();$s=$pdo->query('SELECT * FROM app_user WHERE id=1')->fetch();if(!$s||!password_verify((string)($b['password']??''),$s['password_hash']))fail('Invalid password.',401);session_regenerate_id(true);$_SESSION['user_id']=1;$_SESSION['username']=$s['username'];$_SESSION['csrf']=bin2hex(random_bytes(24));if(!empty($b['remember']))issueRememberToken($pdo);else{if(!empty($_COOKIE['ccm_remember'])){$del=$pdo->prepare('DELETE FROM remember_tokens WHERE token_hash=?');$del->execute([hash('sha256',(string)$_COOKIE['ccm_remember'])]);}clearRememberCookie();}out(['ok'=>true,'csrf'=>$_SESSION['csrf']]); }
 if($action==='logout' && $method==='POST') { csrf();if(!empty($_COOKIE['ccm_remember'])){$del=$pdo->prepare('DELETE FROM remember_tokens WHERE token_hash=?');$del->execute([hash('sha256',(string)$_COOKIE['ccm_remember'])]);}clearRememberCookie();$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));out(['ok'=>true,'csrf'=>$_SESSION['csrf']]); }
 auth(); if(in_array($method,['POST','PUT','PATCH','DELETE'],true)) csrf();
 if($action==='bootstrap' && $method==='GET') {
   // Consolidated first-load payload: avoids multiple simultaneous SQLite connections on shared hosting.
   ensureStatements($pdo);
   $allCards=$pdo->query('SELECT * FROM credit_cards ORDER BY active DESC,name')->fetchAll();
   $statementList=statementRows($pdo); $previousDues=0;
   foreach($statementList as $statement) $previousDues+=(int)$statement['outstanding_minor'];
   $dashboardCards=[];
   foreach($allCards as $card) {
     if(!(int)$card['active']) continue;
     $cycle=cycleFor($card,(new DateTimeImmutable('today'))->format('Y-m-d'));
     $sum=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM transactions WHERE card_id=? AND settlement_date BETWEEN ? AND ?');
     $sum->execute([$card['id'],$cycle['start'],$cycle['end']]);
     $cardStatements=array_values(array_filter($statementList,fn($statement)=>(int)$statement['card_id']===(int)$card['id']));
     $cardDues=0; foreach($cardStatements as $statement) $cardDues+=(int)$statement['outstanding_minor'];
     $card['current_minor']=(int)$sum->fetchColumn(); $card['dues_minor']=$cardDues; $card['cycle_start']=$cycle['start']; $card['cycle_end']=$cycle['end']; $dashboardCards[]=$card;
   }
   $latest=$pdo->query('SELECT t.*,c.name card_name FROM transactions t JOIN credit_cards c ON c.id=t.card_id ORDER BY t.txn_at DESC,t.id DESC LIMIT 5')->fetchAll();
   foreach($latest as &$row){$row['has_attachment']=!empty($row['attachment_path'])?1:0;unset($row['attachment_path']);} unset($row);
   $dashboard=['cards'=>$dashboardCards,'previous_dues_minor'=>$previousDues,'current_expenses_minor'=>array_sum(array_map(fn($card)=>(int)$card['current_minor'],$dashboardCards)),'latest'=>$latest];
   $transactionRows=$pdo->query('SELECT t.*,c.name card_name FROM transactions t JOIN credit_cards c ON c.id=t.card_id ORDER BY t.txn_at DESC,t.id DESC LIMIT 2000')->fetchAll();
   foreach($transactionRows as &$row){$row['has_attachment']=!empty($row['attachment_path'])?1:0;unset($row['attachment_path']);} unset($row);
   $paymentRows=$pdo->query('SELECT p.*,c.name card_name FROM bill_payments p JOIN credit_cards c ON c.id=p.card_id ORDER BY p.paid_at DESC,p.id DESC')->fetchAll();
   foreach($paymentRows as &$row){$allocated=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM payment_allocations WHERE payment_id=?');$allocated->execute([$row['id']]);$row['allocated_minor']=(int)$allocated->fetchColumn();$row['unallocated_minor']=(int)$row['amount_minor']-$row['allocated_minor'];} unset($row);
   $budgetQuery=$pdo->prepare('SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN (?,?)');$budgetQuery->execute(['monthly_budget_minor','categories_json']);$settingRows=$budgetQuery->fetchAll(PDO::FETCH_KEY_PAIR);$categories=json_decode((string)($settingRows['categories_json']??''),true);if(!is_array($categories)||!count($categories))$categories=['Groceries','Fuel','Food & dining','Shopping','Bills & utilities','Travel','Education','Health','Entertainment','Other'];
   out(['cards'=>$allCards,'transactions'=>$transactionRows,'apps'=>$pdo->query('SELECT * FROM upi_apps ORDER BY active DESC,name')->fetchAll(),'dashboard'=>$dashboard,'payments'=>$paymentRows,'statements'=>$statementList,'settings'=>['monthly_budget_minor'=>isset($settingRows['monthly_budget_minor'])?(int)$settingRows['monthly_budget_minor']:0,'categories'=>$categories]]);
 }
 if($action==='settings' && $method==='GET') { $sq=$pdo->prepare('SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN (?,?)');$sq->execute(['monthly_budget_minor','categories_json']);$rows=$sq->fetchAll(PDO::FETCH_KEY_PAIR);$categories=json_decode((string)($rows['categories_json']??''),true);if(!is_array($categories)||!count($categories))$categories=['Groceries','Fuel','Food & dining','Shopping','Bills & utilities','Travel','Education','Health','Entertainment','Other'];out(['settings'=>['monthly_budget_minor'=>isset($rows['monthly_budget_minor'])?(int)$rows['monthly_budget_minor']:0,'categories'=>$categories]]); }
 if($action==='settings' && $method==='POST') { $b=body();$q=$pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');if(array_key_exists('monthly_budget_minor',$b)){$value=$b['monthly_budget_minor'];if(!is_numeric($value)||(int)$value<0||floor((float)$value)!=(float)$value)fail('Budget amount is invalid.');$q->execute(['monthly_budget_minor',(string)(int)$value]);}if(array_key_exists('categories',$b)){$categories=$b['categories'];if(!is_array($categories)||!count($categories)||count($categories)>100)fail('At least one category is required.');$clean=[];foreach($categories as $category){$name=trim((string)$category);if($name===''||mb_strlen($name)>50)fail('Category names must be 1–50 characters.');$clean[$name]=true;}if(count($clean)!==count($categories))fail('Category names must be unique.');$q->execute(['categories_json',json_encode(array_keys($clean),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}out(['ok'=>true]); }
 if($action==='transaction-attachment' && $method==='POST') {
   $id=intv($_POST['transaction_id']??0,'Transaction ID',1);$q=$pdo->prepare('SELECT id,attachment_path FROM transactions WHERE id=?');$q->execute([$id]);$txn=$q->fetch();if(!$txn)fail('Transaction not found.',404);
   if(empty($_FILES['attachment'])||($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('Choose an image attachment.');$file=$_FILES['attachment'];if((int)$file['size']>5*1024*1024)fail('Attachment must be 5 MB or smaller.');
   $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($extensions[$mime]))fail('Only JPG, PNG and WebP images are accepted.');
   $dir=dirname(dbPath()).'/attachments';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))fail('Could not create the attachment directory.',500);$relative='attachments/txn_'.$id.'_'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];$full=dirname(dbPath()).'/'.$relative;if(!move_uploaded_file($file['tmp_name'],$full))fail('Could not save the attachment.',500);
   if(!empty($txn['attachment_path'])){@unlink(dirname(dbPath()).'/'.$txn['attachment_path']);}$u=$pdo->prepare('UPDATE transactions SET attachment_path=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$relative,$id]);out(['ok'=>true]);
 }
 if($action==='transaction-attachment' && $method==='GET') {
   $id=intv($_GET['id']??0,'Transaction ID',1);$q=$pdo->prepare('SELECT attachment_path FROM transactions WHERE id=?');$q->execute([$id]);$relative=$q->fetchColumn();if(!$relative)fail('Attachment not found.',404);$full=dirname(dbPath()).'/'.$relative;if(!is_file($full))fail('Attachment file not found.',404);$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($full);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))fail('Unsupported attachment type.',415);header_remove('Content-Type');header('Content-Type: '.$mime);header('Content-Length: '.filesize($full));header('Content-Disposition: inline; filename="transaction-'.$id.'.'.pathinfo($full,PATHINFO_EXTENSION).'"');header('Cache-Control: private, no-store');readfile($full);exit;
 }
 if($action==='transaction-attachment' && $method==='DELETE') {
   $id=intv($_GET['id']??0,'Transaction ID',1);$q=$pdo->prepare('SELECT attachment_path FROM transactions WHERE id=?');$q->execute([$id]);$relative=$q->fetchColumn();if($relative){@unlink(dirname(dbPath()).'/'.$relative);$u=$pdo->prepare("UPDATE transactions SET attachment_path='',updated_at=CURRENT_TIMESTAMP WHERE id=?");$u->execute([$id]);}out(['ok'=>true]);
 }
 if($action==='dashboard' && $method==='GET') {
   ensureStatements($pdo); $cards=$pdo->query('SELECT * FROM credit_cards WHERE active=1 ORDER BY name')->fetchAll(); $statements=statementRows($pdo);$dues=0;foreach($statements as $s)$dues+=(int)$s['outstanding_minor'];$current=[];
   foreach($cards as $c){$cy=cycleFor($c,(new DateTimeImmutable('today'))->format('Y-m-d'));$q=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM transactions WHERE card_id=? AND settlement_date BETWEEN ? AND ?');$q->execute([$c['id'],$cy['start'],$cy['end']]);$cur=(int)$q->fetchColumn();$cardStatements=array_values(array_filter($statements,fn($s)=>(int)$s['card_id']===(int)$c['id']));$cardDue=0;foreach($cardStatements as $s)$cardDue+=(int)$s['outstanding_minor'];$c['current_minor']=$cur;$c['dues_minor']=$cardDue;$c['cycle_start']=$cy['start'];$c['cycle_end']=$cy['end'];$current[]=$c;}
   $latest=$pdo->query('SELECT t.*,c.name card_name FROM transactions t JOIN credit_cards c ON c.id=t.card_id ORDER BY t.txn_at DESC,t.id DESC LIMIT 5')->fetchAll();foreach($latest as &$row){$row['has_attachment']=!empty($row['attachment_path'])?1:0;unset($row['attachment_path']);}unset($row);out(['cards'=>$current,'previous_dues_minor'=>$dues,'current_expenses_minor'=>array_sum(array_map(fn($c)=>(int)$c['current_minor'],$current)),'latest'=>$latest]);
 }
 if($action==='cards' && $method==='GET') {out(['cards'=>$pdo->query('SELECT * FROM credit_cards ORDER BY active DESC,name')->fetchAll()]);}
 if($action==='cards' && $method==='POST') {$b=body();$name=trim((string)($b['name']??''));$bank=trim((string)($b['bank_name']??''));if(!$name||!$bank)fail('Card name and bank name are required.');$rule=($b['billing_rule']??'end')==='start'?'start':'end';$day=intv($b['billing_day']??5,'Billing day',1);if($day>31)fail('Billing day must be between 1 and 31.');$lim=isset($b['credit_limit_minor'])&&$b['credit_limit_minor']!==''?intv($b['credit_limit_minor'],'Credit limit'):null;$s=$pdo->prepare('INSERT INTO credit_cards(name,bank_name,last4,credit_limit_minor,billing_day,billing_rule,due_days) VALUES(?,?,?,?,?,?,?)');$s->execute([$name,$bank,substr(preg_replace('/\D/','',(string)($b['last4']??'')),-4),$lim,$day,$rule,intv($b['due_days']??20,'Due days')]);out(['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);}
 if($action==='cards' && $method==='PUT') {$b=body();$id=intv($b['id']??0,'Card ID',1);$name=trim((string)($b['name']??''));$bank=trim((string)($b['bank_name']??''));$day=intv($b['billing_day']??5,'Billing day',1);if(!$name||!$bank||$day>31)fail('Check the card details.');$s=$pdo->prepare('UPDATE credit_cards SET name=?,bank_name=?,last4=?,credit_limit_minor=?,billing_day=?,billing_rule=?,due_days=?,active=? WHERE id=?');$s->execute([$name,$bank,substr(preg_replace('/\D/','',(string)($b['last4']??'')),-4),isset($b['credit_limit_minor'])&&$b['credit_limit_minor']!==''?intv($b['credit_limit_minor'],'Credit limit'):null,$day,($b['billing_rule']??'end')==='start'?'start':'end',intv($b['due_days']??20,'Due days'),!empty($b['active'])?1:0,$id]);out(['ok'=>true]);}
 if($action==='cards' && $method==='DELETE') {$id=intv($_GET['id']??0,'Card ID',1);$q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM transactions WHERE card_id=?)+(SELECT COUNT(*) FROM statements WHERE card_id=?)+(SELECT COUNT(*) FROM bill_payments WHERE card_id=?)');$q->execute([$id,$id,$id]);if((int)$q->fetchColumn()>0){$q=$pdo->prepare('UPDATE credit_cards SET active=0 WHERE id=?');$q->execute([$id]);out(['ok'=>true,'archived'=>true]);}$q=$pdo->prepare('DELETE FROM credit_cards WHERE id=?');$q->execute([$id]);out(['ok'=>true]);}
 if($action==='transactions' && $method==='GET') {$where=[];$args=[];foreach(['card_id'=>'t.card_id','month'=>"substr(t.txn_at,1,7)",'method'=>'t.payment_method'] as $k=>$col){if(isset($_GET[$k])&&$_GET[$k]!==''){$where[]="$col=?";$args[]=$_GET[$k];}}if(!empty($_GET['from'])){$where[]='date(t.txn_at)>=?';$args[]=dateOnly((string)$_GET['from']);}if(!empty($_GET['to'])){$where[]='date(t.txn_at)<=?';$args[]=dateOnly((string)$_GET['to']);}if(!empty($_GET['q'])){$where[]='(t.particular LIKE ? OR t.notes LIKE ?)';$args[]='%'.$_GET['q'].'%';$args[]='%'.$_GET['q'].'%';}if(!empty($_GET['upi_app_id'])){$where[]='t.upi_app_id=?';$args[]=(int)$_GET['upi_app_id'];}$sql='SELECT t.*,c.name card_name FROM transactions t JOIN credit_cards c ON c.id=t.card_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY t.txn_at DESC,t.id DESC LIMIT 2000';$q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();foreach($rows as &$row){$row['has_attachment']=!empty($row['attachment_path'])?1:0;unset($row['attachment_path']);}unset($row);out(['transactions'=>$rows]);}
 if($action==='transactions' && in_array($method,['POST','PUT'],true)) {
   $b=body(); $id=$method==='PUT'?intv($b['id']??0,'Transaction ID',1):0; $card=intv($b['card_id']??0,'Card',1);
   $part=trim((string)($b['particular']??'')); $category=trim((string)($b['category']??'')); $tags=trim((string)($b['tags']??''));
   $amt=intv($b['amount_minor']??0,'Amount',1); $txn=validDateTime((string)($b['txn_at']??date('Y-m-d H:i:s'))); $settle=dateOnly((string)($b['settlement_date']??substr($txn,0,10)),'Settlement date');
   $pm=(string)($b['payment_method']??'swipe'); if(!in_array($pm,['swipe','online','upi'],true))fail('Invalid payment method.');
   $appId=null; $appName=''; if($pm==='upi'){$appId=intv($b['upi_app_id']??0,'UPI app',1);$q=$pdo->prepare('SELECT name FROM upi_apps WHERE id=? AND active=1');$q->execute([$appId]);$appName=(string)$q->fetchColumn();if(!$appName)fail('Select an active UPI application.');}
   $q=$pdo->prepare('SELECT id FROM credit_cards WHERE id=? AND active=1');$q->execute([$card]);if(!$q->fetchColumn())fail('Select an active card.'); $notes=trim((string)($b['notes']??''));
   if($method==='PUT'){$oldq=$pdo->prepare('SELECT card_id,settlement_date FROM transactions WHERE id=?');$oldq->execute([$id]);$old=$oldq->fetch();if(!$old)fail('Transaction not found.',404);$guard=$pdo->prepare('SELECT COUNT(*) FROM statements WHERE is_locked=1 AND ((card_id=? AND ? BETWEEN cycle_start AND cycle_end) OR (card_id=? AND ? BETWEEN cycle_start AND cycle_end))');$guard->execute([$old['card_id'],$old['settlement_date'],$card,$settle]);if((int)$guard->fetchColumn()>0)fail('This transaction belongs to a manually locked statement. Unlock that statement before editing.',409);}
   else{$guard=$pdo->prepare('SELECT COUNT(*) FROM statements WHERE card_id=? AND is_locked=1 AND ? BETWEEN cycle_start AND cycle_end');$guard->execute([$card,$settle]);if((int)$guard->fetchColumn()>0)fail('This billing cycle is manually locked. Unlock its statement before adding a transaction.',409);}
   if($method==='POST'){$s=$pdo->prepare('INSERT INTO transactions(card_id,particular,category,tags,amount_minor,txn_at,settlement_date,payment_method,upi_app_id,upi_app_name,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$card,$part,$category,$tags,$amt,$txn,$settle,$pm,$appId,$appName,$notes]);out(['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);}
   else{$s=$pdo->prepare('UPDATE transactions SET card_id=?,particular=?,category=?,tags=?,amount_minor=?,txn_at=?,settlement_date=?,payment_method=?,upi_app_id=?,upi_app_name=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$card,$part,$category,$tags,$amt,$txn,$settle,$pm,$appId,$appName,$notes,$id]);out(['ok'=>true]);}
 }
 if($action==='transactions' && $method==='DELETE') {$id=intv($_GET['id']??0,'Transaction ID',1);$q=$pdo->prepare('SELECT settlement_date,card_id FROM transactions WHERE id=?');$q->execute([$id]);$t=$q->fetch();if(!$t)fail('Transaction not found.',404);$q=$pdo->prepare('SELECT COUNT(*) FROM statements WHERE is_locked=1 AND card_id=? AND ? BETWEEN cycle_start AND cycle_end');$q->execute([$t['card_id'],$t['settlement_date']]);if((int)$q->fetchColumn()>0)fail('This transaction belongs to a manually locked statement. Unlock that statement before deleting it.',409);$q=$pdo->prepare('DELETE FROM transactions WHERE id=?');$q->execute([$id]);out(['ok'=>true]);}
 if($action==='statements' && $method==='GET') {$card=isset($_GET['card_id'])?(int)$_GET['card_id']:null;ensureStatements($pdo,$card);$rows=statementRows($pdo,$card);out(['statements'=>$rows]);}
 if($action==='statement-action' && $method==='POST') { $b=body();$id=intv($b['id']??0,'Statement ID',1);$op=(string)($b['operation']??'');$q=$pdo->prepare('SELECT * FROM statements WHERE id=?');$q->execute([$id]);$st=$q->fetch();if(!$st)fail('Statement not found.',404);if($op==='confirm'){$bank=intv($b['bank_amount_minor']??-1,'Bank statement amount',0);$q=$pdo->prepare('UPDATE statements SET bank_amount_minor=?,confirmed_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$bank,$id]);out(['ok'=>true,'confirmed'=>true,'difference_minor'=>$bank-(int)$st['amount_minor']]);}if($op==='lock'){if(empty($st['confirmed_at']))fail('Confirm the statement against your bank statement before locking it.');$q=$pdo->prepare('UPDATE statements SET is_locked=1 WHERE id=?');$q->execute([$id]);out(['ok'=>true,'is_locked'=>true]);}if($op==='unlock'){$q=$pdo->prepare('UPDATE statements SET is_locked=0 WHERE id=?');$q->execute([$id]);out(['ok'=>true,'is_locked'=>false]);}fail('Unknown statement operation.'); }
 if($action==='payments' && $method==='GET') {ensureStatements($pdo);$q=$pdo->query('SELECT p.*,c.name card_name FROM bill_payments p JOIN credit_cards c ON c.id=p.card_id ORDER BY p.paid_at DESC,p.id DESC');$rows=$q->fetchAll();foreach($rows as &$r){$s=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM payment_allocations WHERE payment_id=?');$s->execute([$r['id']]);$r['allocated_minor']=(int)$s->fetchColumn();$r['unallocated_minor']=(int)$r['amount_minor']-$r['allocated_minor'];}$statements=statementRows($pdo);out(['payments'=>$rows,'statements'=>$statements]);}
 if($action==='payments' && $method==='POST') {$b=body();$card=intv($b['card_id']??0,'Card',1);$amt=intv($b['amount_minor']??0,'Payment amount',1);$paid=validDateTime((string)($b['paid_at']??date('Y-m-d H:i:s')));$q=$pdo->prepare('SELECT id FROM credit_cards WHERE id=?');$q->execute([$card]);if(!$q->fetchColumn())fail('Card not found.');ensureStatements($pdo,$card);$pdo->beginTransaction();try{$s=$pdo->prepare('INSERT INTO bill_payments(card_id,amount_minor,paid_at,reference,notes) VALUES(?,?,?,?,?)');$s->execute([$card,$amt,$paid,trim((string)($b['reference']??'')),trim((string)($b['notes']??''))]);$pid=(int)$pdo->lastInsertId();allocatePayment($pdo,$pid,$card,$amt);$pdo->commit();out(['ok'=>true,'id'=>$pid],201);}catch(Throwable $e){$pdo->rollBack();throw $e;}}
 if($action==='payments' && $method==='DELETE') {$id=intv($_GET['id']??0,'Payment ID',1);$q=$pdo->prepare('DELETE FROM bill_payments WHERE id=?');$q->execute([$id]);out(['ok'=>true]);}
 if($action==='upi-apps' && $method==='GET') out(['apps'=>$pdo->query('SELECT * FROM upi_apps ORDER BY active DESC,name')->fetchAll()]);
 if($action==='upi-apps' && $method==='POST') {$name=trim((string)(body()['name']??''));if(!$name)fail('Application name is required.');try{$s=$pdo->prepare('INSERT INTO upi_apps(name) VALUES(?)');$s->execute([$name]);out(['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);}catch(PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique'))fail('That UPI app already exists.',409);throw $e;}}
 if($action==='upi-apps' && $method==='PUT') {$b=body();$id=intv($b['id']??0,'App ID',1);$name=trim((string)($b['name']??''));if(!$name)fail('Application name is required.');$s=$pdo->prepare('UPDATE upi_apps SET name=?,active=? WHERE id=?');$s->execute([$name,!empty($b['active'])?1:0,$id]);out(['ok'=>true]);}
 if($action==='upi-apps' && $method==='DELETE') {$id=intv($_GET['id']??0,'App ID',1);$s=$pdo->prepare('UPDATE upi_apps SET active=0 WHERE id=?');$s->execute([$id]);out(['ok'=>true]);}
 fail('Unknown API action.',404);
} catch(Throwable $e) { if($pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack(); error_log('Card Ledger API: '.$e->getMessage()); out(['error'=>'A server error occurred. Check the application log.'],500); }
