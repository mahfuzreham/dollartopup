<?php
declare(strict_types=1);
$db=require __DIR__.'/../config/database.php';
function envv(string $k,string $d=''):string{return (string)(getenv($k)?:$d);}
$rpc=envv('BSC_RPC_URL','https://bsc-dataseed.bnbchain.org');
$confirm=max(1,(int)envv('BSC_CONFIRMATIONS','12'));
$usdt=strtolower(envv('BSC_USDT_CONTRACT','0x55d398326f99059ff775485246999027b3197955'));
function rpc(string $url,string $method,array $params=[]):?array{if(!function_exists('curl_init'))return null;$h=curl_init($url);$p=json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]);curl_setopt_array($h,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$p,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>15]);$r=curl_exec($h);curl_close($h);$j=json_decode((string)$r,true);return is_array($j)?$j:null;}
$q=$db->query("SELECT w.order_no,w.amount,w.destination_address,w.tx_hash FROM withdrawal_requests w WHERE w.status='verifying' AND w.tx_hash IS NOT NULL LIMIT 25");
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $w){try{
$r=rpc($rpc,'eth_getTransactionReceipt',[$w['tx_hash']]);$rc=$r['result']??null;if(!$rc)continue;
if(($rc['status']??'')!=='0x1')throw new RuntimeException('Transaction failed');
$latest=rpc($rpc,'eth_blockNumber')['result']??'0x0';$cn=hexdec($latest)-hexdec($rc['blockNumber'])+1;if($cn<$confirm)continue;
$to='000000000000000000000000'.strtolower(substr($w['destination_address'],2));$topic='0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
$want=(int)round((float)$w['amount']*1000000000000000000);$ok=false;
foreach(($rc['logs']??[]) as $log){$topics=$log['topics']??[];$data=$log['data']??'';if(strtolower($log['address']??'')===$usdt&&strtolower($topics[0]??'')===$topic&&strtolower($topics[2]??'')==='0x'.$to&&hexdec($data)===$want){$ok=true;break;}}
if(!$ok)throw new RuntimeException('USDT transfer mismatch');
$db->prepare("UPDATE withdrawal_requests SET status='completed',updated_at=NOW(),verification_error=NULL WHERE order_no=?")->execute([$w['order_no']]);
$db->prepare("UPDATE orders SET withdrawal_status='completed' WHERE order_no=?")->execute([$w['order_no']]);
}catch(Throwable $e){$db->prepare("UPDATE withdrawal_requests SET verification_error=?,updated_at=NOW() WHERE order_no=?")->execute([substr($e->getMessage(),0,255),$w['order_no']]);}}
