<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Day;
use App\Location;
use App\Subject;
use Auth;
use Carbon\Carbon;

use App\Course;
use App\User;
use App\Course_subject;
use App\Course_day;
use App\CourseStudent;
use App\Notification;
use App\CourseClass;

class CourseController extends Controller
{
    public function fetchDays(){
        $days = Day::all()->pluck('name');
        return $days;
    }

    public function fetchSubjects(){
        $days = Subject::all()->pluck('name');
        return $days;
    }

    public function newCourse(Request $request){
        $location = Location::firstOrCreate(
          ['locationId' => $request->locationId],
          ['name' => $request->area, 'address' => $request->address, 'latitude' => $request->center['lat'],'longitude' =>$request->center['lng']]
        );

        $course = new Course;
        $course->time = $request->time;
        $course->hours = $request->hours;
        $course->startDate = new Carbon($request->startDate);
        $course->price = $request->price;
        $course->noClasses = $request->noClasses;
        $course->studentCount = $request->studentCount;
        $course->user()->associate(Auth::user());
        $course->location()->associate($location);
        $course->save();

        $days = Day::whereIn('name', $request->days)->get()->pluck('id');
        $subjects = Subject::whereIn('name', $request->subjects)->get()->pluck('id');
        $course->subjects()->sync($subjects);
        $course->days()->sync($days);
        $course->save();
        $this->newClasses($course);
        return response('OK', 200);
    }

    public static function getCourseInfo($courseId) {
        // find course
        $course = Course::find($courseId);

        // find days
        $days = $course->days->pluck('name');

        // find subject
        $subjects = $course->subjects->pluck('name');

        // find tutor's name
        $tutorName = $course->user->name;

        $returnObj = [
            'tutor_name' => $tutorName,
            'course_id' => $course->id,
            'area' => $course->location->name,
            'time' => $course->time,
            'hour' => $course->hours,
            'startDate' => $course->startDate,
            'price' => $course->price,
            'days' => $days,
            'subjects' => $subjects,
            'noClass' => $course->noClasses,
            'studentCount' => $course->studentCount 
        ];

        return $returnObj;
    }

    public function cancelCourse(Request $request){
        $user_id = $request->user_id;
        $course_id = $request->course_id;
        $registeredCourse = CourseStudent::where('user_id', $user_id)->where('course_id', '=', $course_id)->first();
        $registeredCourse->status = 'refunding';
        $registeredCourse->save();
        
        //  create cancel notification
        $username = User::where('id','=',$registeredCourse->user_id)->first()->name;
        $title = "Request to teach";
        $message = "{$username} have cancel the course";
        $receiver_id = Course::where('id','=',$registeredCourse->course_id)->first()->user_id;
        NotificationController::createNotification($receiver_id, $title, $message);

        return response($registeredCourse, 200);
    }

    public function getStatus($course_id){
        $status = '';
        if(auth()->user()->role == 'student'){
            $status = CourseStudent::where('user_id', auth()->user()->id)->where('course_id', '=', $course_id)->first()->status;
        }
        return response($status, 200);
    }

    public function myCoursesIndex(){
        $user = auth()->user();
        $courses;
        $classDateList = [];
        $nextClasses = [];
        $isFinished = [];
        $classesLeft = [];
        if($user->isStudent()){
            $courses = $user->registeredCourses()->with(['days', 'subjects', 'location'])->orderBy('startDate', 'DESC')->paginate(10)->onEachSide(1);
        }
        else if($user->isTutor()){
            $courses = Course::with(['days', 'subjects', 'location'])->where('user_id', auth()->user()->id)->orderBy('startDate', 'DESC')->paginate(10)->onEachSide(1);
        }
        foreach($courses as $course){
            $now = Carbon::now()->addHours(7);
            $nextClass = $course->courseClasses->sortBy('date')->where('date', '>=', $now)->first();
            $lastClass = $course->courseClasses->sortBy('date')->last();
            array_push($classDateList, $course->courseClasses->sortBy('date')->pluck('date')->all());
            array_push($nextClasses, $nextClass?$nextClass->date:NULL);
            array_push($classesLeft, $course->courseClasses->where('date', '>=', $now)->count());
            array_push($isFinished, $lastClass?$lastClass->date < $now:NULL);
        }
        return view('my_courses', ['courses' => $courses, 'classDateList' => $classDateList, 'nextClasses' => $nextClasses, 'isFinished' => $isFinished, 'classesLeft' => $classesLeft]);
    }

    public function requestCourse(Request $request) {
        $data = array('course_id'=>$request->course_id,"requester_id"=>auth()->user()->id);
        DB::table("courses_requester")->insert($data);

        // create Notification 
        $username = User::where('id','=',auth()->user()->id)->first()->name;
        $message = "{$username} have request to teach your course";
        $title = "Request to teach";
        $receiver_id = Course::where('id','=',$request->course_id)->first()->user_id;
        NotificationController::createNotification($receiver_id, $title, $message);

        return response()->json(array('msg'=> "Done"), 200);
    }

    public function search(Request $request) {
        $student_name = $request->input("student_name");
        $area = $request->input("area");
        $subject = $request->input("subject");
        $day = $request->input("day");
        $time = $request->input("time");
        $num_students = $request->input("num_students");
        $max_price = $request->input("max_price");
        $courses = DB::table('courses')->paginate(15);
        return view("/tutor_search_course",compact('courses'));
    }

    public static function newClasses($course){
        $weekMap = [
            'Sunday' => 0,
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
        ];
        $weekDays = $course->days->pluck('name')->toArray();
        $count = $course->noClasses;
        $now = new Carbon($course->startDate);
        $now->subDays(1);
        while($count != 0){
            $min = $now->copy()->addDays(8);
            foreach($weekDays as $weekDay){
                $t = $now->copy()->next($weekMap[$weekDay]);
                if($t->lt($min)){
                    $min = $t;
                }
            }
            $courseClass = new CourseClass;
            $courseClass->date = $min;
            $courseClass->time = $course->time;
            $courseClass->course_id = $course->id;
            $courseClass->hours = $course->hours;
            $courseClass->save();
            $now = $min;
            $count = $count - 1;
        }
    }
}
