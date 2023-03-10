<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Transaksi;
use App\TransaksiDetail;
use App\User;

class TransaksiController extends Controller
{
    public function store(Request $request){
        //nama, email, password
        $validasi = Validator::make($request->all(), [
            'user_id' => 'required',
            'total_item' => 'required',
            'total_harga' => 'required',
            'jasa_pengiriman' => 'required',
            'ongkir' => 'required',
            'total_transfer' => 'required',
            'bank' => 'required',
            'phone' => 'required',
        ]);

        if($validasi->fails()){
            $val = $validasi->errors()->all();
            return $this->error($val[0]);
        }

        $kode_payment = "INV/PYM/".now()->format('Y-m-d')."/".rand(100, 999);
        $kode_trx = "INV/PYM/".now()->format('Y-m-d')."/".rand(100, 999);
        $kode_unik = rand(100, 999);
        $status = "MENUGGU";
        $expired_at = now()->addDay();

        $dataTransaksi = array_merge($request->all(), [
            'kode_payment' => $kode_payment,
            'kode_trx' => $kode_trx,
            'kode_unik' => $kode_unik,
            'status' => $status,
            'expired_at' => $expired_at,
        ]);

        \DB::beginTransaction();
        $transaksi = Transaksi::create($dataTransaksi);
        foreach ($request->produks as $produk){
            $detail = [
                'transaksi_id' => $transaksi->id,
                'produk_id' => $produk['id'],
                'catatan' => $produk['catatan'],
                'total_item' => $produk['total_item'],
                'total_harga' => $produk['total_harga']
            ];
            $transaksiDetail = TransaksiDetail::create($detail);

        }

        if (!empty($transaksi) && !empty($transaksiDetail)){
            \DB::commit();
            return response()->json([
                'success' => 1,
                'message' => 'Transaksi Berhasil',
                'transaksi' => collect($transaksi)
            ]);
        } else{
            \DB::rollback();
            $this->error('Transaksi Gagal');
        }
    }



    public function history($id){

        $transaksis = Transaksi::with(['user'])->whereHas('user', function ($query) use ($id){
            $query->whereId($id);
        })->orderBy("id", "desc")->get();

        foreach ($transaksis as $transaksi){
            $details = $transaksi->details;
            foreach ($details as $detail){
                $detail->produk;
            }
        }

        if (!empty($transaksis)){
            return response()->json([
                'success' => 1,
                'message' => 'Transaksi Berhasil',
                'transaksis' => collect($transaksis)
            ]);
        } else{
            $this->error('Transaksi Gagal');
        }

    }

    public function batal($id){
        $transaksi = Transaksi::with(['details.produk', 'user'])->where('id', $id)->first();
        if ($transaksi){
            //update data

            $transaksi->update([
                'status' => "BATAL"
            ]);

            $this->pushNotif('Transaksi dibatalkan', "Transaksi produk ". $transaksi->details[0]->produk->name ." berhasil dibatalkan", $transaksi->user->fcm);

            return response()->json([
                'success' => 1,
                'message' => 'Berhasil',
                'transaksi' => $transaksi
            ]);
        }else{
            return $this->error('Gagal memuat transaksi');
        }
    }

    public function pushNotif($title, $message, $mFcm) {

        $mData = [
            'title' => $title,
            'body' => $message
        ];

        $fcm[] = "$mFcm";

        $payload = [
            'registration_ids' => $fcm,
            'notification' => $mData
        ];

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://fcm.googleapis.com/fcm/send",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => array(
                "Content-type: application/json",
                "Authorization: key=AAAAYN3IIIA:APA91bGPStYaSqsCCdtEvENi5zAOk6gEFHr6rqDdtcM0uMSPLpqsQLwduaYGrxEr6IEsQQJxFEIRTjMIcDCD_QTiAfPXte0E7YWjpEts0LDNUAcSRUZgVSRm6-hPxm4MI6fWdwXV9h6h"
            ),
        ));
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($curl);
        curl_close($curl);

        $data = [
            'success' => 1,
            'message' => "Push notif success",
            'data' => $mData,
            'firebase_response' => json_decode($response)
        ];
        return $data;
    }

    public function upload(Request $request, $id){
        $transaksi = Transaksi::with(['details.produk', 'user'])->where('id', $id)->first();
        if ($transaksi){
            //update data

            $fileName = '';
        if($request->image->getClientOriginalName()){
            $file = str_replace(' ', '', $request->image->getClientOriginalName());
            $fileName = date('mYdHs').rand(1,999). '_' .$file;
            $request->image->storeAs('public/transfer', $fileName);
        } else{
            return $this->error('Gagal memuat data');
        }


            $transaksi->update([
                'status' => "DIBAYAR",
                'buktiTransfer' => $fileName
            ]);

            $this->pushNotif('Transaksi dibayar', "Transaksi produk ". $transaksi->details[0]->produk->name ." berhasil dibayar", $transaksi->user->fcm);

            return response()->json([
                'success' => 1,
                'message' => 'Berhasil',
                'transaksi' => $transaksi
            ]);
        }else{
            return $this->error('Gagal memuat transaksi');
        }
    }

    public function error($pesan){
        return response()->json([
            'success' => 0,
            'message' => $pesan
        ]); 
    }
}
