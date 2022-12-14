<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use App\Http\Resources\User as UserResource;

use App\User;
use Hash;

class UserControllerAPI extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('deleted_at', null)->paginate(10);
        switch ($request->filter) {
            case "Blocked":
                $query = User::where('blocked', 1)->paginate(10);
                break;
            case "Unblocked":
                $query = User::where('blocked', 0)->paginate(10);
                break;
            case "Soft Deleted":
                $query = User::where('deleted_at', '!=' , null)->paginate(10);
                break;
        }
        return UserResource::collection($query);
    }

    public function show($id)
    {
        return new UserResource(User::find($id));
    }

    public function store(Request $request)
    {
        $request->validate([
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|email|unique:users'
        ]);

        if($request->password !== $request->confirmationPassword) {
            $image = $request->photo_url;
            if($image != null) {
                $image = str_replace('data:image/jpeg;base64,', '', $image);
                $image = str_replace(' ', '+', $image);
                $imageName = str_random(10).'.'.'jpeg';
                \File::put(storage_path().'/app/public/profiles/'.$imageName, base64_decode($image));
                $date=date_create();
                date_timestamp_get($date);
                
                DB::table('users')->insert(
                    [
                        "name" => $request->name,
                        "username" => $request->username,
                        "email" => $request->email,
                        "password" => Hash::make($request->password),
                        "type" => $request->type,
                        "blocked" => 0,
                        "photo_url" => $imageName,
                        "shift_active" => 0,
                        "created_at" => date_format($date,"Y-m-d H:i:s"),
                        "updated_at" => date_format($date,"Y-m-d H:i:s")
                    ] 
                );
                return response()->json(null, 201);
            } else {
                return response()->json("Photo missing", 400);
            }    
        } else {
            return response()->json("Passwords missmatch", 400);
        } 
    }

    public function blockUnblock(Request $request, $id)
    {
        if($request->operation == 1) {
            DB::table('users')->where('id', $id)->update(array('blocked' => 1));
        } else if($request->operation == 0) {
            DB::table('users')->where('id', $id)->update(array('blocked' => 0));
        } else {
            return response()->json("Error with operation number", 400);
        }
    }

    public function update(Request $request, $id)
    {
        if ($id != Auth::id()) {
            return response()->json(null, 401);
        }

        $request->merge(array_map('trim', $request->all()));

        $data = $request->validate([
            'name' => 'string|min:3|regex:/^[A-Za-z?????????????????????????????????????????????????? ]+$/',
            'username' => 'string|min:2|regex:/^[A-Za-z??????????????????????????????????????????????????0-9_-]+$/|unique:users',
        ]);

        if (empty($data)) {
            return response()->json(null, 400);
        }

        $user = User::findOrFail(Auth::id());
        $user->update($data);
        return new UserResource($user);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        try {
            $user->delete();
        }
        catch (\Exception $e) {
            $date=date_create();
            date_timestamp_get($date);
            User::where('id', $id)->update(array('deleted_at' => date_format($date,"Y-m-d H:i:s")));
        }
        return response()->json(null, 204);
    }

    public function emailAvailable(Request $request)
    {
        $totalEmail = 1;
        if ($request->has('email') && $request->has('id')) {
            $totalEmail = DB::table('users')->where('email', '=', $request->email)->where('id', '<>', $request->id)->count();
        } else if ($request->has('email')) {
            $totalEmail = DB::table('users')->where('email', '=', $request->email)->count();
        }

        return response()->json($totalEmail == 0);
    }

    public function myProfile(Request $request)
    {
        return new UserResource($request->user());
    }

    public function uploadPhoto(Request $request, $id) {
        if ($id != Auth::id()) {
            return response()->json(null, 401);
        }

        $data = $request->validate([
            'photo' => 'required|image',
        ]);

        $user = User::findOrFail($id);
        $file = $data['photo'];

        if (!is_null($user->photo_url)) {
            Storage::disk('public')->delete('profiles/'.$user->photo_url);
        }

        if (!Storage::disk('public')->exists('profiles/'.$file->hashname())) {
            $file->store('profiles', 'public');
        }

        $user->photo_url = $file->hashname();
        $user->save();
        return response()->json(["data" => $file->hashname()]);
    }

    public function toggleShift($id) {
        if ($id != Auth::id()) {
            return response()->json(null, 401);
        }

        $user = User::findOrFail($id);
        if ($user->shift_active == 1) {
            $shift_active = 0;
            $user->last_shift_end = Carbon::now()->toDateTimeString();

        } else {
            $shift_active = 1;
            $user->last_shift_start = Carbon::now()->toDateTimeString();
        }

        $user->shift_active = $shift_active;
        $user->save();
        return response()->json(["data" => $user]);
    }

    public function changePassword(Request $request, $id) {
        if ($id != Auth::id()) {
            return response()->json(null, 401);
        }

        if ($request->password === null) {
            return response()->json(null, 400);
        }

        $user = User::findOrFail($id);
        $user->password = Hash::make($request->password);
        $user->save();

        return new UserResource($user);
    }
}
