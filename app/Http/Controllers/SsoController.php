<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Str;

class SsoController extends Controller
{
    public function handleCallback(Request $request)
    {
        if (!$request->has('data')) {
            return redirect('/login')->with('error', 'Parameter data tidak ditemukan.');
        }

        try {
            $encryptedData = urldecode($request->input('data'));
            $encryptedData = str_replace(' ', '+', $encryptedData);
            
            $decodedData = base64_decode($encryptedData);
            
            $key = '666666'; 
            $decryptedDataxor = $this->xorDecrypt($decodedData, $key);
            
            $decryptedData = base64_decode($decryptedDataxor);
            $json = json_decode($decryptedData, true);

            if (!$json || !isset($json['email']) || !isset($json['token'])) {
                return redirect('/login')->with('error', 'Format data SSO tidak valid.');
            }

            $email = $json['email'];
            $token = $json['token'];
            $firstName = $json['firstname'] ?? strstr($email, '@', true);
            $employeeId = $json['employee_no'] ?? null; 

            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic S1BOX1NTTzpUTXNfJDU2T3BzJXB3',
            ])->post('https://kpncorporation.darwinbox.com/checkToken', [
                'api_key' => '3bbfc6dfa28df2a81bd45192bf4f96b72628ae0ec9921a062aef937b7f25d6c704ccfc9539e70e5939a45cc43f3b7ce61477c7135a83bdbd6f85d5c38b5fc563',
                'token'   => $token,
            ]);

            session(['sso_token' => $token]);

            if ($response->successful() && isset($response['status']) && $response['status'] == 1) {
                
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $firstName,
                        'employee_id' => $employeeId,
                        'password' => bcrypt(Str::random(16)), 
                        'email_verified_at' => now(),
                    ]
                );

                $user->update([
                    'name' => $firstName,
                    'employee_id' => $employeeId
                ]);

                Auth::login($user);
                
                return redirect()->route('admin.dashboard'); 

            } else {
                return redirect('/login')->with('error', 'Validasi Token Darwinbox Gagal.');
            }

        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'System Error: ' . $e->getMessage());
        }
    }

    private function xorDecrypt($data, $key)
    {
        $keyLength = strlen($key);
        $dataLength = strlen($data);
        $decrypted = '';

        for ($i = 0; $i < $dataLength; $i++) {
            $decrypted .= $data[$i] ^ $key[$i % $keyLength];
        }

        return $decrypted;
    }
}