<?php
require_once __DIR__.'/../includes/bootstrap.php';require_login();
$phone=preg_replace('/\D+/','',(string)($_GET['phone']??''));
if(!preg_match('/^0\d{9}$/',$phone)) json_response(['message'=>'Enter a valid 10-digit mobile number.'],422);
$stmt=db()->prepare('SELECT drinks_count,total_rewards FROM customer_loyalty WHERE phone=?');$stmt->execute([$phone]);$row=$stmt->fetch();
$count=(int)($row['drinks_count']??0);
json_response(['phone'=>$phone,'drinks_count'=>$count,'reward_ready'=>$count>=5,'message'=>$count>=5?'Free drink is ready for this customer.':$count.'/5 drinks collected • '.(5-$count).' more for a free drink.']);
