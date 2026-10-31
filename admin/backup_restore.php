<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();

function ms_backup_tables(PDO $pdo): array {
    $rows=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    return array_map(static fn($r)=>(string)$r[0],$rows);
}

function ms_sql_value(PDO $pdo,mixed $value): string {
    if($value===null) return 'NULL';
    if(is_bool($value)) return $value?'1':'0';
    return $pdo->quote((string)$value);
}

function ms_make_backup(PDO $pdo): string {
    $out="-- Misty Sip Database Backup\n";
    $out.="-- Generated: ".date('Y-m-d H:i:s')." Asia/Colombo\n";
    $out.="-- Restore only through Misty Sip Admin > Backup & Restore\n\n";
    $out.="SET NAMES utf8mb4;\n";
    $out.="SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach(ms_backup_tables($pdo) as $table){
        $quoted='`'.str_replace('`','``',$table).'`';
        $create=$pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_ASSOC);
        $createSql=(string)(array_values($create)[1]??'');
        $out.="-- ----------------------------\n";
        $out.="-- Table: {$table}\n";
        $out.="-- ----------------------------\n";
        $out.="DROP TABLE IF EXISTS {$quoted};\n";
        $out.=$createSql.";\n\n";

        $stmt=$pdo->query("SELECT * FROM {$quoted}");
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
            $cols=array_map(static fn($c)=>'`'.str_replace('`','``',(string)$c).'`',array_keys($row));
            $vals=array_map(static fn($v)=>ms_sql_value($pdo,$v),array_values($row));
            $out.="INSERT INTO {$quoted} (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n";
        }
        $out.="\n";
    }
    $out.="SET FOREIGN_KEY_CHECKS=1;\n";
    $out.="-- End Misty Sip Database Backup\n";
    return $out;
}

function ms_split_sql(string $sql): array {
    $statements=[];$buffer='';$quote=null;$escape=false;$len=strlen($sql);
    for($i=0;$i<$len;$i++){
        $ch=$sql[$i];

        // Strip full-line comments only when not inside a quoted value.
        if($quote===null && $ch==='-' && ($i+1<$len && $sql[$i+1]==='-')){
            $prev=$i===0?"\n":$sql[$i-1];
            if($prev==="\n" || $prev==="\r"){
                while($i<$len && $sql[$i]!=="\n") $i++;
                $buffer.="\n";
                continue;
            }
        }

        if($escape){$buffer.=$ch;$escape=false;continue;}
        if($quote!==null){
            $buffer.=$ch;
            if($ch==='\\'){$escape=true;continue;}
            if($ch===$quote){
                // SQL doubled quote ('') stays inside the same quoted string.
                if(($quote==="'" || $quote==='"') && $i+1<$len && $sql[$i+1]===$quote){
                    $buffer.=$sql[++$i];
                    continue;
                }
                $quote=null;
            }
            continue;
        }

        if($ch==="'" || $ch==='"' || $ch==='`'){$quote=$ch;$buffer.=$ch;continue;}
        if($ch===';'){
            $stmt=trim($buffer);
            if($stmt!=='')$statements[]=$stmt;
            $buffer='';
            continue;
        }
        $buffer.=$ch;
    }
    $tail=trim($buffer);
    if($tail!=='')$statements[]=$tail;
    return $statements;
}

$error='';
$success='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='download_backup'){
        try{
            log_activity('Database Backup Downloaded','system','Admin downloaded a full SQL database backup.');
            $sql=ms_make_backup(db());
            $filename='misty_sip_backup_'.date('Y-m-d_His').'.sql';
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('Content-Length: '.strlen($sql));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo $sql;
            exit;
        }catch(Throwable $e){
            $error='Backup could not be created: '.$e->getMessage();
        }
    }

    if($action==='restore_backup'){
        try{
            $file=$_FILES['backup_file']??null;
            if(!$file || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
                throw new RuntimeException('Please select a valid SQL backup file.');
            }
            if(($file['size']??0)>20*1024*1024){
                throw new RuntimeException('Backup file must be 20 MB or smaller.');
            }
            $name=(string)($file['name']??'');
            if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='sql'){
                throw new RuntimeException('Only .sql backup files are allowed.');
            }
            $sql=file_get_contents((string)$file['tmp_name']);
            if($sql===false || trim($sql)===''){
                throw new RuntimeException('The selected backup file is empty.');
            }
            if(!str_contains($sql,'-- Misty Sip Database Backup')){
                throw new RuntimeException('This is not a Misty Sip backup file.');
            }
            if(!str_contains($sql,'-- End Misty Sip Database Backup')){
                throw new RuntimeException('The backup file appears incomplete.');
            }

            $pdo=db();
            $statements=ms_split_sql($sql);
            if(count($statements)<2)throw new RuntimeException('No valid SQL statements were found.');

            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try{
                foreach($statements as $statement){
                    $trimmed=ltrim($statement);
                    if($trimmed==='')continue;
                    $pdo->exec($statement);
                }
            }finally{
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            log_activity('Database Restored','system','Admin restored database from backup file: '.basename($name));
            $_SESSION['flash']='Database restored successfully from '.basename($name).'.';
            header('Location: backup_restore.php?restored=1');
            exit;
        }catch(Throwable $e){
            try{db()->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable $ignored){}
            $error='Restore failed: '.$e->getMessage();
        }
    }
}

if(isset($_GET['restored'])){
    $success='Database restore completed successfully.';
}

$pageTitle='Backup & Restore';
$pageSubtitle='Protect your Misty Sip POS data with full SQL backups.';
include __DIR__.'/../includes/header.php';
?>
<style>
.backup-page{padding-bottom:70px}
.backup-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
.backup-card{background:#fff;border:1px solid #dce8e3;border-radius:18px;padding:24px;box-shadow:0 8px 24px rgba(20,70,53,.05)}
.backup-card-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.backup-card-icon{width:52px;height:52px;border-radius:14px;display:grid;place-items:center;flex:0 0 52px}
.backup-card-icon svg{width:26px;height:26px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.backup-card.download .backup-card-icon{background:#e7f4ff;color:#2563eb}
.backup-card.restore .backup-card-icon{background:#fff3e6;color:#d97706}
.backup-card h2{margin:0 0 5px;color:#153c2e;font-size:21px}
.backup-card p{margin:0;color:#667b72;line-height:1.55;font-size:14px}
.backup-info{margin:18px 0;padding:14px 15px;border:1px solid #e0ebe6;background:#f8fbfa;border-radius:12px;color:#52665e;font-size:13px;line-height:1.6}
.backup-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 18px;border:0;border-radius:11px;font-weight:800;cursor:pointer;text-decoration:none}
.backup-btn.primary{background:#07865c;color:#fff}
.backup-btn.danger{background:#d94b4b;color:#fff}
.backup-btn.secondary{background:#eef3f1;color:#33483f}
.backup-file{display:block;width:100%;box-sizing:border-box;margin:14px 0;padding:12px;border:1px dashed #aac8bc;border-radius:12px;background:#fbfdfc}
.backup-warning{display:flex;gap:9px;margin:14px 0;color:#9a4b16;background:#fff8ef;border:1px solid #f3d4ae;border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.45}
.backup-alert{margin-bottom:16px;padding:12px 14px;border-radius:11px;font-weight:700}
.backup-alert.error{background:#fff0f0;color:#b12d2d;border:1px solid #f3c3c3}
.backup-alert.success{background:#eaf8f1;color:#08714d;border:1px solid #bfe6d3}
.backup-note{margin-top:18px;background:#f7faf9;border:1px solid #e0e9e5;border-radius:14px;padding:16px;color:#53675f;font-size:13px;line-height:1.6}
.restore-overlay{position:fixed;inset:0;z-index:100100;background:rgba(15,23,42,.54);display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.restore-overlay.open{display:flex}
.restore-modal{width:min(440px,100%);background:#fff;border-radius:20px;padding:27px;box-shadow:0 25px 80px rgba(0,0,0,.3)}
.restore-modal-icon{width:58px;height:58px;border-radius:16px;display:grid;place-items:center;background:#fff0f0;color:#cf3f3f;font-size:26px;font-weight:900;margin-bottom:14px}
.restore-modal h3{margin:0 0 8px;color:#153c2e;font-size:22px}
.restore-modal p{margin:0;color:#65786f;line-height:1.55;font-size:14px}
.restore-modal-actions{display:flex;gap:10px;margin-top:22px}
.restore-modal-actions button{flex:1}
@media(max-width:900px){.backup-grid{grid-template-columns:1fr}}
</style>

<section class="backup-page">
  <?php if($error):?><div class="backup-alert error"><?=e($error)?></div><?php endif;?>
  <?php if($success):?><div class="backup-alert success"><?=e($success)?></div><?php endif;?>

  <div class="backup-grid">
    <article class="backup-card download">
      <div class="backup-card-head">
        <span class="backup-card-icon"><svg viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5z"/><path d="M8 4v6h8V4M12 13v5M9.5 15.5 12 18l2.5-2.5"/></svg></span>
        <div><h2>Create Database Backup</h2><p>Download a complete SQL copy of the current Misty Sip database.</p></div>
      </div>
      <div class="backup-info">
        Includes database table structures, sales, sale items, users, shifts, stock, activity logs and other stored POS data.
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="download_backup">
        <button class="backup-btn primary" type="submit">Download SQL Backup</button>
      </form>
    </article>

    <article class="backup-card restore">
      <div class="backup-card-head">
        <span class="backup-card-icon"><svg viewBox="0 0 24 24"><path d="M12 4v10M8.5 7.5 12 4l3.5 3.5"/><path d="M5 13v7h14v-7"/></svg></span>
        <div><h2>Restore Database</h2><p>Restore the POS database using a backup previously downloaded from Misty Sip.</p></div>
      </div>
      <div class="backup-warning"><strong>Important:</strong><span>Restore replaces the current database tables and data with the selected backup. Create a fresh backup first if the current data may be needed.</span></div>
      <form method="post" enctype="multipart/form-data" id="restoreForm">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="restore_backup">
        <input class="backup-file" type="file" name="backup_file" id="backupFile" accept=".sql" required>
        <button class="backup-btn danger" type="submit">Restore Database</button>
      </form>
    </article>
  </div>

  <div class="backup-note">
    <strong>Recommended:</strong> Create a backup before major product, stock, user or system changes. Keep copies on another drive or secure cloud storage instead of only on the POS computer.
  </div>
</section>

<div class="restore-overlay" id="restoreOverlay" aria-hidden="true">
  <div class="restore-modal" role="dialog" aria-modal="true" aria-labelledby="restoreConfirmTitle">
    <div class="restore-modal-icon">!</div>
    <h3 id="restoreConfirmTitle">Restore this database backup?</h3>
    <p>The current database will be replaced by the data inside the selected backup file. This action cannot be undone unless you have another backup.</p>
    <div class="restore-modal-actions">
      <button class="backup-btn secondary" type="button" id="restoreCancel">Cancel</button>
      <button class="backup-btn danger" type="button" id="restoreConfirm">Yes, Restore</button>
    </div>
  </div>
</div>

<script>
(function(){
  const form=document.getElementById('restoreForm');
  const file=document.getElementById('backupFile');
  const overlay=document.getElementById('restoreOverlay');
  const cancel=document.getElementById('restoreCancel');
  const confirm=document.getElementById('restoreConfirm');
  let approved=false;

  form.addEventListener('submit',function(e){
    if(approved)return;
    e.preventDefault();
    if(!file.files.length){file.focus();return;}
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
  });
  function close(){
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
  }
  cancel.addEventListener('click',close);
  overlay.addEventListener('click',e=>{if(e.target===overlay)close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&overlay.classList.contains('open'))close();});
  confirm.addEventListener('click',function(){
    approved=true;
    close();
    form.submit();
  });
})();
</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
