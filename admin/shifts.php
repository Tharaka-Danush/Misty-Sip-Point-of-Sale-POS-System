<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();

$from=$_GET['from']??date('Y-m-d');
$to=$_GET['to']??date('Y-m-d');
$cashierId=(int)($_GET['cashier_id']??0);
$status=$_GET['status']??'all';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=date('Y-m-d');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=date('Y-m-d');
if(!in_array($status,['all','open','closed'],true)) $status='all';

$cashiers=db()->query("SELECT id,name FROM users WHERE role='cashier' ORDER BY name")->fetchAll();
$sql="SELECT cs.*,u.name cashier FROM cashier_shifts cs JOIN users u ON u.id=cs.user_id WHERE DATE(cs.shift_started_at) BETWEEN ? AND ?";
$params=[$from,$to];
if($cashierId>0){$sql.=" AND cs.user_id=?";$params[]=$cashierId;}
if($status==='open') $sql.=" AND cs.closed_at IS NULL";
if($status==='closed') $sql.=" AND cs.closed_at IS NOT NULL";
$sql.=" ORDER BY cs.shift_started_at DESC,cs.id DESC";
$stmt=db()->prepare($sql);$stmt->execute($params);$shifts=$stmt->fetchAll();

$open=0;$sales=0;$difference=0;
foreach($shifts as $s){if(empty($s['closed_at']))$open++;$sales+=(float)$s['total_sales'];if(!empty($s['closed_at']))$difference+=(float)$s['cash_difference'];}
function shift_duration_text(string $start, ?string $end): string {
    $a=new DateTime($start); $b=$end?new DateTime($end):new DateTime(); $d=$a->diff($b);
    $hours=($d->days*24)+$d->h; return $hours.'h '.$d->i.'m';
}
function money($v): string { return 'LKR '.number_format((float)$v,2); }
$pageTitle='Cashier Shifts';$pageSubtitle='Review cashier opening, closing and cash reconciliation';
include __DIR__.'/../includes/header.php';
?>
<form class="filters panel shift-filters">
  <label>From<input type="date" name="from" value="<?=e($from)?>"></label>
  <label>To<input type="date" name="to" value="<?=e($to)?>"></label>
  <label>Cashier<select name="cashier_id"><option value="0">All Cashiers</option><?php foreach($cashiers as $c):?><option value="<?=e($c['id'])?>" <?=$cashierId===(int)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></label>
  <label>Status<select name="status"><option value="all" <?=$status==='all'?'selected':''?>>All Status</option><option value="open" <?=$status==='open'?'selected':''?>>Open</option><option value="closed" <?=$status==='closed'?'selected':''?>>Closed</option></select></label>
  <button class="btn primary">Apply</button><a class="btn" href="shifts.php">Reset</a>
</form>
<section class="stats shift-stats shift-stats-pro">
 <article><small>Total Shifts</small><strong><?=count($shifts)?></strong></article>
 <article><small>Open Shifts</small><strong><?=$open?></strong></article>
 <article><small>Total Sales</small><strong><?=money($sales)?></strong></article>
 <article><small>Cash Difference</small><strong class="<?=$difference<0?'negative':($difference>0?'positive':'')?>"><?=money($difference)?></strong></article>
</section>
<section class="panel cashier-shifts-panel">
 <div class="shift-list-head"><div><h2>Shift History</h2><p><?=date('d M Y',strtotime($from))?> — <?=date('d M Y',strtotime($to))?></p></div><small class="shift-readonly-note">Closed shift cash figures are read-only audit records.</small></div>
 <div class="table-wrap"><table class="shift-history-table shift-history-compact"><thead><tr><th>Shift</th><th>Cashier</th><th>Status</th><th>Opened</th><th>Closed</th><th>Duration</th><th>Total Sales</th><th>Difference</th><th></th></tr></thead><tbody>
 <?php foreach($shifts as $s): $isOpen=empty($s['closed_at']); $diff=(float)$s['cash_difference']; $balanced=!$isOpen && abs($diff)<0.005; ?>
 <tr>
  <td><strong>#<?=e($s['id'])?></strong></td><td><strong><?=e($s['cashier'])?></strong></td><td><span class="shift-status <?=$isOpen?'open':'closed'?>"><?=$isOpen?'OPEN':'CLOSED'?></span></td>
  <td><?=date('d M Y, h:i A',strtotime($s['shift_started_at']))?></td><td><?=$isOpen?'—':date('d M Y, h:i A',strtotime($s['closed_at']))?></td><td><?=e(shift_duration_text($s['shift_started_at'],$s['closed_at']?:null))?></td>
  <td><?=money($s['total_sales'])?></td>
  <td><?php if($isOpen):?>—<?php elseif($balanced):?><span class="balanced">Balanced</span><?php else:?><span class="<?=$diff<0?'negative':'positive'?>"><?=$diff>0?'+':''?><?=money($diff)?></span><?php endif;?></td>
  <td><button type="button" class="btn shift-view-btn" data-shift-b64='<?=base64_encode(json_encode([
    'id'=>$s['id'],'cashier'=>$s['cashier'],'status'=>$isOpen?'OPEN':'CLOSED','opened'=>date('d M Y, h:i A',strtotime($s['shift_started_at'])),'closed'=>$isOpen?'—':date('d M Y, h:i A',strtotime($s['closed_at'])),'duration'=>shift_duration_text($s['shift_started_at'],$s['closed_at']?:null),'opening'=>money($s['opening_cash']),'bills'=>(int)$s['total_bills'],'cash'=>money($s['cash_total']),'card'=>money($s['card_total']),'other'=>money($s['other_total']),'sales'=>money($s['total_sales']),'expected'=>$isOpen?'—':money($s['expected_cash']),'actual'=>$isOpen?'—':money($s['actual_cash']),'difference'=>$isOpen?'—':($balanced?'Balanced':(($diff>0?'+':'').money($diff))),'diffClass'=>$isOpen||$balanced?'':($diff<0?'negative':'positive'),'note'=>(!$isOpen&&abs($diff)>=0.005)?($s['note']?:'—'):'','isOpen'=>$isOpen
  ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>'>View</button></td>
 </tr>
 <?php endforeach; ?>
 <?php if(!$shifts):?><tr><td colspan="9" class="empty">No cashier shifts found for the selected filters.</td></tr><?php endif;?>
 </tbody></table></div>
</section>

<style id="cashierShiftModalStyles">
html body .shift-detail-modal{position:fixed!important;inset:0!important;z-index:99999!important;display:none!important;align-items:center!important;justify-content:center!important;padding:24px!important;background:rgba(4,24,17,.58)!important;backdrop-filter:blur(5px);}
html body .shift-detail-modal.show{display:flex!important;}
html body .shift-detail-backdrop{position:absolute!important;inset:0!important;background:transparent!important;}
html body .shift-detail-dialog{position:relative!important;z-index:2!important;width:min(820px,calc(100vw - 40px))!important;max-height:90vh!important;overflow:auto!important;background:#fff!important;border-radius:22px!important;box-shadow:0 28px 90px rgba(0,0,0,.32)!important;}
html body .shift-detail-head{display:flex!important;justify-content:space-between!important;gap:20px!important;padding:22px 24px!important;background:linear-gradient(125deg,#063d2a,#0f7a50)!important;color:#fff!important;}
html body .shift-detail-head h2{margin:4px 0!important;color:#fff!important;} html body .shift-detail-head p{margin:0!important;color:#d8f2e5!important;}
html body .shift-detail-body{padding:22px 24px!important;} html body .shift-detail-top,html body .shift-time-row,html body .shift-money-grid{display:grid!important;gap:10px!important;}
html body .shift-detail-top{grid-template-columns:repeat(3,1fr)!important;} html body .shift-time-row{grid-template-columns:repeat(2,1fr)!important;margin-top:10px!important;} html body .shift-money-grid{grid-template-columns:repeat(3,1fr)!important;margin-top:16px!important;}
html body .shift-detail-top>div,html body .shift-time-row>div,html body .shift-money-grid>div{padding:13px!important;border:1px solid #dce8e2!important;border-radius:12px!important;background:#f8fbf9!important;}
html body .shift-detail-body span{display:block!important;margin-bottom:5px!important;color:#6a7b73!important;font-size:10px!important;font-weight:800!important;text-transform:uppercase!important;}
html body .shift-detail-body strong{color:#173c2f!important;font-size:14px!important;} html body .shift-detail-note{margin-top:14px!important;padding:14px!important;border:1px solid #f0d6b5!important;border-radius:12px!important;background:#fff9f1!important;}
html body .shift-detail-actions{display:flex!important;justify-content:flex-end!important;gap:10px!important;padding:0 24px 22px!important;} html body.modal-open{overflow:hidden!important;}
html body .shift-modal-x{width:38px!important;height:38px!important;border:0!important;border-radius:50%!important;background:rgba(255,255,255,.12)!important;color:#fff!important;font-size:25px!important;cursor:pointer!important;}
@media(max-width:700px){html body .shift-detail-top,html body .shift-time-row,html body .shift-money-grid{grid-template-columns:1fr!important;}}
</style>
<div class="shift-detail-modal" id="shiftDetailModal" aria-hidden="true" style="display:none">
 <div class="shift-detail-backdrop" data-close-shift-modal></div>
 <div class="shift-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="shiftDetailTitle">
  <div class="shift-detail-head"><div><small>SHIFT AUDIT RECORD</small><h2 id="shiftDetailTitle">Shift Summary</h2><p id="sdMeta"></p></div><button type="button" class="shift-modal-x" data-close-shift-modal aria-label="Close">×</button></div>
  <div class="shift-detail-body" id="shiftPrintableArea">
   <div class="shift-detail-top"><div><span>Cashier</span><strong id="sdCashier"></strong></div><div><span>Status</span><strong id="sdStatus"></strong></div><div><span>Duration</span><strong id="sdDuration"></strong></div></div>
   <div class="shift-time-row"><div><span>Opened</span><strong id="sdOpened"></strong></div><div><span>Closed</span><strong id="sdClosed"></strong></div></div>
   <div class="shift-money-grid">
    <div><span>Opening Cash</span><strong id="sdOpening"></strong></div><div><span>Total Bills</span><strong id="sdBills"></strong></div><div><span>Cash Sales</span><strong id="sdCash"></strong></div><div><span>Card Sales</span><strong id="sdCard"></strong></div><div><span>Other Payments</span><strong id="sdOther"></strong></div><div><span>Total Sales</span><strong id="sdSales"></strong></div><div><span>Expected Cash</span><strong id="sdExpected"></strong></div><div><span>Actual Cash</span><strong id="sdActual"></strong></div><div class="shift-difference-box"><span>Cash Difference</span><strong id="sdDifference"></strong></div>
   </div>
   <div class="shift-detail-note" id="sdNoteWrap"><span>Difference Note</span><p id="sdNote"></p></div>
  </div>
  <div class="shift-detail-actions"><button type="button" class="btn" data-close-shift-modal>Close</button><button type="button" class="btn primary" id="printShiftDetail">Print Shift Summary</button></div>
 </div>
</div>
<script>
(function(){
 const modal=document.getElementById('shiftDetailModal'); let current=null;
 const set=(id,val)=>document.getElementById(id).textContent=val;
 function closeModal(){modal.classList.remove('show');modal.style.display='none';modal.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');}
 document.querySelectorAll('.shift-view-btn').forEach(btn=>btn.addEventListener('click',()=>{
   try {
   const raw=atob(btn.getAttribute('data-shift-b64')||''); const bytes=Uint8Array.from(raw,c=>c.charCodeAt(0)); current=JSON.parse(new TextDecoder('utf-8').decode(bytes)); set('shiftDetailTitle','Shift #'+current.id+' Summary'); set('sdMeta',current.opened+' • '+current.cashier); set('sdCashier',current.cashier); set('sdStatus',current.status); set('sdDuration',current.duration); set('sdOpened',current.opened); set('sdClosed',current.closed); set('sdOpening',current.opening); set('sdBills',current.bills); set('sdCash',current.cash); set('sdCard',current.card); set('sdOther',current.other); set('sdSales',current.sales); set('sdExpected',current.expected); set('sdActual',current.actual); set('sdDifference',current.difference);
   const diff=document.getElementById('sdDifference'); diff.className=current.diffClass||''; document.getElementById('sdNoteWrap').style.display=current.note?'block':'none'; set('sdNote',current.note||''); document.getElementById('printShiftDetail').style.display=current.isOpen?'none':'inline-flex'; modal.style.display='flex';modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');
   } catch(err) { console.error('Shift view error',err); alert('Unable to load shift details. Please refresh and try again.'); }
 }));
 document.querySelectorAll('[data-close-shift-modal]').forEach(el=>el.addEventListener('click',closeModal));
 document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
 document.getElementById('printShiftDetail').addEventListener('click',()=>{ window.print(); closeModal(); });
})();
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
