<?php
/*
  Copyright 2014 Neill Miller (neillm@altcoinlabs.com, neillm@thecodefactory.org)

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful, but
  WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
  General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see
  <http://www.gnu.org/licenses/>.
*/

require_once('jsonRPCClient.php');
require_once('localsite.inc');

/*
REQUIREMENTS:
- mongodb
- php5-mongodb
- the mongo db has been created and setup beforehand as follows:

NOTE: DATABASE must match the coin name (e.g. "bitcoin")

use DATABASE
db.dropDatabase()
db.createCollection('blocks')
db.createCollection('transactions')
db.blocks.insert({"global":"last_block","last_block":0})
db.blocks.insert({"global":"last_connected_block","last_connected_block":0})
*/

function get_block_and_transaction_collections($coin_name)
{
    $conn = new MongoClient();
    $db = $conn->selectDB($coin_name);
    if ($db)
    {
        $block_coll = $db->selectCollection("blocks");
        $transaction_coll = $db->selectCollection("transactions");
    }
    return array($block_coll, $transaction_coll);
}

function get_rpc_client()
{
    return new jsonRPCClient("http://".RPC_USERNAME.":".RPC_PASSWORD."@".RPC_HOSTNAME.":".RPC_PORT."/");
}

function get_block_hash($client, $index)
{
    return $client->getblockhash($index);
}

function get_block_data($client, $hash)
{
    return $client->getblock($hash);
}

function get_last_block($coll)
{
    $last_block = -1;
    $query = array('global' => 'last_block');
    $doc = $coll->findOne($query);
    if ($doc)
    {
        $last_block = $doc['last_block'];
    }
    return intval($last_block);
}

function set_last_block($coll, $last_block)
{
    $query = array('global' => 'last_block');
    $doc = $coll->findOne($query);
    if ($doc)
    {
        $doc['last_block'] = $last_block;
        $coll->save($doc);
    }
    else
    {
        print("FAILED TO SET LAST BLOCK\n");
    }
}

function crawl_blockchain($coin_name, $block_coll, $transaction_coll, $start)
{
    $client = get_rpc_client();
    while(1)
    {
        try
        {
            $hash = get_block_hash($client, $start);
            $data = get_block_data($client, $hash);

            $txs = $data['tx'];
            $num_txs = count($txs);

            print("Crawling block $start ... (num_tx = $num_txs)\n");

            $tx_array = array();
            for($i = 0; $i < $num_txs; $i++)
            {
                try
                {
                    $cur_tx = $txs[$i];
                    if ($start > 0)
                    {
                        $raw_tx = $client->getrawtransaction($cur_tx);
                        $decoded_tx = $client->decoderawtransaction($raw_tx);
                    }
                    else
                    {
                        $raw_tx = null;
                        $decoded_tx = null;
                    }

                    array_push($tx_array, $cur_tx);

                    $vin_array = array();
                    $num_vin = count($decoded_tx['vin']);
                    for($j = 0; $j < $num_vin; $j++)
                    {
                        if (isset($decoded_tx['vin'][$j]['coinbase']))
                        {
                            $cur_vin_array = array(
                                'coinbase' => $decoded_tx['vin'][$j]['coinbase'],
                                'sequence' => $decoded_tx['vin'][$j]['sequence']);

                            array_push($vin_array, $cur_vin_array);
                        }
                        else if (isset($decoded_tx['vin'][$j]['scriptSig']))
                        {
                            $script_sig_array = array(
                                'asm' => $decoded_tx['vin'][$j]['scriptSig']['asm'],
                                'hex' => $decoded_tx['vin'][$j]['scriptSig']['hex']);

                            $cur_vin_array = array(
                                'txid' => $decoded_tx['vin'][$j]['txid'],
                                'vout' => $decoded_tx['vin'][$j]['vout'],
                                'scriptSig' => $script_sig_array,
                                'sequence' => $decoded_tx['vin'][$j]['sequence']);

                            array_push($vin_array, $cur_vin_array);
                        }
                    }

                    $vout_array = array();
                    $num_vout = count($decoded_tx['vout']);
                    for($j = 0; $j < $num_vout; $j++)
                    {
                        $cur_address_array = $decoded_tx['vout'][$j]['scriptPubKey']['addresses'];

                        $cur_script_array = array(
                            'asm' => $decoded_tx['vout'][$j]['scriptPubKey']['asm'],
                            'hex' => $decoded_tx['vout'][$j]['scriptPubKey']['hex'],
                            'reqSigs' => $decoded_tx['vout'][$j]['scriptPubKey']['reqSigs'],
                            'type' => $decoded_tx['vout'][$j]['scriptPubKey']['type'],
                            'addresses' => $cur_address_array);

                        $cur_vout_array = array(
                            'value' => $decoded_tx['vout'][$j]['value'],
                            'n' => $decoded_tx['vout'][$j]['n'],
                            'scriptPubKey' => $cur_script_array);

                        array_push($vout_array, $cur_vout_array);
                    }

                    $tx_data_array = array(
                        'txid' => $decoded_tx['txid'],
                        'version' => $decoded_tx['version'],
                        'locktime' => $decoded_tx['locktime'],
                        'vin' => $vin_array,
                        'vout' => $vout_array);

                    $tx_data = array(
                        'index' => $i,
                        'block_index' => $start,
                        'hash' => $cur_tx,
                        'data' => $tx_data_array);

                    $transaction_coll->insert($tx_data);
                }
                catch(Exception $tx_e)
                {
                    print("Skipping transaction: No info: $tx_e\n");
                }
            }

            $block_data = array(
                'index' => $start,
                'data' => array(
                    'hash' => $hash,
                    "size" => $data['size'],
                    "height" => $data['height'],
                    //"confirmations" => $data['confirmations'],
                    "version" => $data['version'],
                    "merkleroot" => $data['merkleroot'],
                    "tx" => $tx_array,
                    "time" => $data['time'],
                    "nonce" => $data['nonce'],
                    "bits" => $data['bits'],
                    "difficulty" => $data['difficulty'],
                    // genesis block has no previous hash
                    "previousblockhash" => ($start == 0) ? '' : $data['previousblockhash'],
                    "nextblockhash" => $data['nextblockhash']));

            $block_coll->insert($block_data);
            $start++;
            set_last_block($block_coll, $start);
        }
        catch(Exception $e)
        {
            print("Failed to retrieve block info at index $start: $e\n");
            break;
        }
    }
}

/********************************************************
 * Main Program Entry
 ********************************************************/

$coin_name = COIN_NAME;
list($block_coll, $transaction_coll) = get_block_and_transaction_collections($coin_name);
$start = get_last_block($block_coll);
print("Starting run at block index $start ...\n");
if ($start != -1)
{
    crawl_blockchain($coin_name, $block_coll, $transaction_coll, $start);
}

?>
