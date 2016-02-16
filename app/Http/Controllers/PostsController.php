<?php

namespace App\Http\Controllers;


use App\Answers;
use App\Comments;
use App\Courses;
use App\Learnings;
use App\Http\Controllers\Auth\AuthController;
use App\Posts;
use App\Questions;
use App\User;
use App\Doexams;
use App\ConstsAndFuncs;
use App\Tags;
use App\Hashtags;
use App\Spaces;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

class PostsController extends Controller
{

	public function viewNewestPosts(){
//        $posts = Posts::take(5)->skip(0)->get()->toArray();
		$Posts = Posts::orderBy('id', 'desc')->paginate(5);
		$newpost = Posts::orderBy('visited', 'dsc')->take(5)->get();
		$paginateBaseLink = '/';
		// dd($newpost);
		// dd($Posts);
		// dd($Posts->toArray());
		return view('userindex')->with(compact(['Posts', 'newpost', 'paginateBaseLink']));
	}

	public function viewPost($postID){

		if (!auth() || !(auth()->user())){
			$browser = get_browser(null, true);

			// Allow crawler && Facebook Bot view post without logging in.
			if (
				($browser['crawler'] != 1) &&
				(stripos($_SERVER["HTTP_USER_AGENT"], 'facebook') === false) &&
				(stripos($_SERVER["HTTP_USER_AGENT"], 'face') === false) &&
				(stripos($_SERVER["HTTP_USER_AGENT"], 'google') === false)
			){
				$redirectPath = '/post/' . $postID;
				//return redirect('/login')->with('redirectPath', $redirectPath);
			}
			$token = md5(rand(), false);
			$DisplayedQuestions = ConstsAndFuncs::$FreeQuestionsForCrawler;
		}

		$post = Posts::find($postID);
		if (count($post) < 1){
			return view('errors.404');
		}
		$post->visited++;
		$post->update();
		$post = $post->toArray();
		$courseID = $post['CourseID'];
		 if (auth() && (auth()->user())){
			$userID = auth()->user()->getAuthIdentifier();
			$tmp = Learnings::where('UserID', '=', $userID)->where('CourseID', '=', $courseID)->get()->toArray();
			if (count($tmp) < 1){
				$learnings = new Learnings();
				$learnings->UserID = $userID;
				$learnings->CourseID = $courseID;
				$learnings->save();
				$course = Courses::find($courseID);
				$course->NoOfUsers++;
				$course->update();
			}

			// Insert a new record into DoExams Table to mark the time user start answering questions in post.
			$exam = new Doexams();
			$exam->UserID = $userID;
			$exam->PostID = $postID;
			$token = md5($userID . rand(), false) . md5($postID . rand(), false);
			$exam->token = $token;
			$exam->save();

			// Check if user is vip or not
			$user = User::find(auth()->user()->getAuthIdentifier());
			if ($user['vip'] == 0){
				$DisplayedQuestions = $post['NoOfFreeQuestions'];
			}
			else{
				$DisplayedQuestions = ((new \DateTime($user['expire_at'])) >= (new \DateTime())) ? -1 : $post['NoOfFreeQuestions'];
			}
			if ($user['admin'] >= ConstsAndFuncs::PERM_ADMIN){
				$DisplayedQuestions = -1;
			}
		 }

		$photo = $post['Photo'];
		if ($DisplayedQuestions > 0)
			$questions = Questions::where('PostID', '=', $postID)->take($DisplayedQuestions)->get()->toArray();
		else
			$questions = Questions::where('PostID', '=', $postID)->get()->toArray();
		$AnswersFor1 = array();
		$AnswersFor2 = array();
		$Spaces = array();
		$maxscore = 0;
		foreach ($questions as $q){
			switch ($q['FormatID']){
				case 1:		// Trắc nghiệm
					$answers = Answers::where('QuestionID', '=', $q['id'])->get()->toArray();
					$info = [$q['id'] => $answers];
					if (count($answers) > 0)
						$maxscore++;
					$AnswersFor1 += $info;
					continue;
				case 2:		// Điền từ
					$spaces = Spaces::where('QuestionID', '=', $q['id'])->get()->toArray();
					$Spaces += [$q['id'] => $spaces];
					foreach ($spaces as $s) {
						$a = Answers::where('SpaceID', '=', $s['id'])->get()->toArray();
						shuffle($a);
						$AnswersFor2 += [$s['id'] => $a];
					}
					if (count($spaces) > 0)
						$maxscore++;
					continue;
			}
		}
		$Comments = Comments::all()->toArray();
		$result = array(
			'Comments' => json_encode($Comments),
			'Questions' => $questions,
			'Post' => $post,
			'MaxScore' => $maxscore,
			'NumOfQuestions' => count($questions = Questions::where('PostID', '=', $postID)->get()->toArray()),
			'Token' => $token,
			'DisplayedQuestions' => $DisplayedQuestions
		);
		$nextPost = Posts::where('CourseID', '=', $post['CourseID'])->where('id', '>=', $post['id'])->get()->toArray();
		$result += ['NextPost' => (count($nextPost) > 1) ? $nextPost[1]['id'] : Posts::where('CourseID', '=', $post['CourseID'])->first()->toArray()['id']];
		$previousPost = Posts::where('CourseID', '=', $post['CourseID'])->where('id', '<', $post['id'])->get()->toArray();
		$result += ['PreviousPost' => (count($previousPost) > 0) ? $previousPost[count($previousPost) - 1]['id'] : Posts::where('CourseID', '=', $post['CourseID'])->orderBy('created_at', 'desc')->first()->toArray()['id']];
		$newpost = array_merge($nextPost, $previousPost);
		$result += ['newpost' => $newpost];
		// dd($newpost);
		return view('viewpost')->with($result)->with(compact([
			'result', 
			'newpost', 
			// Answers for Format Trắc nghiệm
			'AnswersFor1',
			// Spaces + Answers for Format Điền từ
			'Spaces', 
			'AnswersFor2',
		]));
	}

    public function addPost(){
//        $courses = Courses::all();
//        $courses->toArray();
		if (!AuthController::checkPermission()){
			return redirect('auth/login');
		}
		return view('admin.addpost');
	}

	public function savePost(Request $request){
		if (!AuthController::checkPermission()){
			return redirect('/');
		}
		$data = $request->all();

		$post = new Posts();
		$post->CourseID = $data['CourseID'];
		$post->ThumbnailID = $data['ThumbnailID'];
		$post->Title = $data['Title'];
		$post->Description = $data['Description'];
		$post->NoOfFreeQuestions = $data['NoOfFreeQuestions'];

		switch ($data['ThumbnailID']){
			case '1': // Plain Text
				$post->save();
				$post = Posts::orderBy('id', 'desc')->first();
				//Photo
				$file = $request->file('Photo');
//              $file = Request::file('Photo');
				$post->Photo = 'Post_' . $data['CourseID'] . '_' . $post->id . "_-Evangels-English-www.evangelsenglish.com_" . "." . $file->getClientOriginalExtension();
				$file->move(base_path() . '/public/images/imagePost/', $post->Photo);


				// (intval(Posts::orderBy('created_at', 'desc')->first()->id) + 1)


				$post->update();
				break;
			case '2': // Video
				$linkVideo = $data['Video'];
				$post->Video = PostsController::getYoutubeVideoID($linkVideo);
				$post->save();
				break;
		}
		$course = Courses::find($post->CourseID);
		$course->NoOfPosts++;
		$course->update();

		// Save Hashtag
		$rawHT = $data['Hashtag'];
		TagsController::tag($rawHT, $post->id);

		return redirect(route('admin.viewcourse', $post->CourseID));
	}

	public static function getYoutubeVideoID($rawLink){
		preg_match_all('/watch[?]v=([^&]+)/', $rawLink, $matches, PREG_PATTERN_ORDER);
		if ((count($matches) > 1) && (count($matches[1]) > 0)){
			return $matches[1][0];
		}
		preg_match_all('/youtu.be\/([^?&]+)/', $rawLink, $matches, PREG_PATTERN_ORDER);
		if ((count($matches) > 1) && (count($matches[1]) > 0)){
			return $matches[1][0];
		}
		return null;
	}

	public function edit($id)
	{
		if (!AuthController::checkPermission()){
			return redirect('/');
		}
		$Post = Posts::find($id);
		$ahtk = Tags::where('PostID', '=', $id)->get()->toArray();
		$Hashtag = '';
		foreach ($ahtk as $k){
			$ht = Hashtags::find($k['HashtagID'])['Hashtag'];
			if (strlen($ht) > 0)
			$Hashtag .= '#' . $ht . ' ';
		}
		return view('admin.editpost', compact('Post') + array('Hashtag' => $Hashtag));
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  int  $id
	 * @return \Illuminate\Http\Response
	 */
	public function update(Request $request, $id)
	{
		if (!AuthController::checkPermission()){
			return redirect('/');
		}
		$data = $request->all();
		$post = Posts::find($id);
		$post->CourseID = $data['CourseID'];
		$post->ThumbnailID = $data['ThumbnailID'];
		$post->NoOfFreeQuestions = $data['NoOfFreeQuestions'];
		$post->Title = $data['Title'];
		if ($post->ThumbnailID == '2'){ // Thumbnail Quizz Video
			$post->Video = PostsController::getYoutubeVideoID($data['Video']);
		}
		$post->Description = $data['Description'];
		$post->update();

		if ($post->ThumbnailID == '1'){ // Thumbnail Quizz Plain Text
			// if admin upload new photo
			if ($request->file('Photo') != null) {
				$post = Posts::find($id);

				$file = $request->file('Photo');
				//        $file = Request::file('Photo');
				$post->Photo = 'Post_' . $data['CourseID'] . '_' . $post->id . "_-Evangels-English-www.evangelsenglish.com_" . "." . $file->getClientOriginalExtension();
				$file->move(base_path() . '/public/images/imagePost/', $post->Photo);


				// (intval(Posts::orderBy('created_at', 'desc')->first()->id) + 1)


				$post->update();
			}
		}

		// Update tags
		TagsController::removeTag($post->id);
		TagsController::tag($data['Hashtag'], $post->id);

		return redirect(route('user.viewpost', $post->id));
	}


	public function searchPostsByHashtag(Request $request){
		$data = $request->all();
		preg_match_all('/\b([a-zA-Z0-9]+)\b/', strtoupper($data['HashtagSearch']), $matches, PREG_PATTERN_ORDER);
		$hashtags = $matches[1];
		$posts = Posts::all()->toArray();
		$rank = array();
		foreach ($hashtags as $ht){
			foreach ($posts as $key => $post){
				if (!array_key_exists($key, $rank)){
					$rank += array($key => 0);
				}
				$postHashtag = Tags::where('PostID', '=', $post['id'])->get()->toArray();
				foreach ($postHashtag as $pht){
					if (stripos(Hashtags::find($pht['HashtagID'])->Hashtag, $ht) !== false){
						$rank[$key]++;
					}
				}

			}
		}

		foreach ($rank as $key => $value){
			if ($value < 1){
				unset($rank[$key]);
			}
		}
		arsort($rank);
		$result = array();
		$posts = Posts::all();
		foreach ($rank as $key => $value) {
			$result += array($key => $posts[$key]);
		}
		preg_match_all('/\b([a-zA-Z0-9]+)\b/', $data['HashtagSearch'], $matches, PREG_PATTERN_ORDER);
		$hashtags = $matches[1];
		$Hashtags = '';
		foreach ($hashtags as $ht){
			$Hashtags .= $ht . ' ';
		}
		return view('search')->with(['Posts' => $result, 'Hashtags' => $Hashtags]);
	}

	public static function destroy($id)
	{
		if (!AuthController::checkPermission()){
			return redirect('/');
		}
		$post = Posts::find($id);
		@unlink(public_path('images/imagePost/' . $post['Photo']));
		$questions = Questions::where('PostID', '=', $id)->get()->toArray();
		foreach ($questions as $question) {
			QuestionsController::destroy($question['id']);
		}
		$courseid = $post['CourseID'];
		$post->delete();
		$course = Courses::find($post->CourseID);
		$course->NoOfPosts--;
		$course->update();
		return redirect(route('admin.viewcourse', $courseid));
	}
}
