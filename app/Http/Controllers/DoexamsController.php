<?php

namespace App\Http\Controllers;

use App\Courses;
use App\Doexams;
use App\Posts;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

class DoexamsController extends Controller
{

	public function saveScore(Request $request){
		if (!auth() || !(auth()->user())){
			echo 'Kết quả chưa được lưu.';
			return;
		}
		$UserID = auth()->user()->getAuthIdentifier();
		$data = $request->all();
		$token = $data['token'];

		// day by day, no. of record will increase
		// => maybe there are multiple record with the same value of UserID and token
		// => pick the newest record
		$record = Doexams::where('token', 'LIKE', $token)->where('UserID', '=', $UserID)->get()->last();
		if (count($record->toArray()) < 1){
			echo 'Kết quả chưa được lưu.';
			return;
		}
		$record->Score = $request['Score'] . '/' . $request['MaxScore'];
		$record->update();
		$oldDateTime = $record->created_at->getTimestamp();
		$newDateTime = $record->updated_at->getTimestamp();
		$diff = ($newDateTime - $oldDateTime) / 3600.0;
		$record->Time = $diff;
		$record->update();
		if ($diff > 0){
			$course = Courses::find(Posts::find($record->PostID)->CourseID);
			$course->TotalHours += $diff;
			$course->update();
		}
		echo 'Kết quả đã được lưu lại.';
		return;
	}

}
