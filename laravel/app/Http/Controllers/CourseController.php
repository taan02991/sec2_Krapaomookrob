<?php

namespace App\Http\Controllers;

use App\Advertisement;
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
use App\CourseRequester;
use App\Http\Controllers\Frontend\paymentGatewayController;
use App\Logging;
use App\RefundRequest;
use Illuminate\Validation\Rule;

use function GuzzleHttp\Promise\exception_for;

class CourseController extends Controller
{
    public function fetchDays(){
        $days = Day::all()->pluck('name');
        return $days;
    }

    public function fetchSubjects(){
        $subjects = Subject::all()->pluck('name');
        return $subjects;
    }

    public function newCourse(Request $request){

        $daysinweek = $this->fetchDays()->toArray();
        $subjectlist = $this->fetchSubjects()->toArray();

        // Validator

        $dateTime = $request->input('startDate').'-'.$request->input('time');
        $request->merge([
            'dateTime' => $dateTime,
        ]);

        $data = request()->validate([
            'subjects' => 'required',
            'days' => 'required',
            'time' => 'date_format:H:i|required',
            'hours' => ['required',Rule::in(['1','2','3','4','5'])],
            'startDate' => 'date_format:Y-m-d|required',
            //'dateTime' => 'date_format:Y-m-d-H:i|after_or_equal:now',
            'price' => 'required|gte:0',
            'noClasses' => 'required|gt:0',
            'studentCount' => ['required',Rule::in(['1','2','3','4','5'])],

            'locationId' => 'nullable',
            'area' => 'nullable',
            'address' => 'nullable',
            'center' => 'nullable',
        ]);

        // Validate if each day is days in a week
        $days = $request -> days;
        if(!empty($days)){
            foreach($days as $day){
                if(!in_array($day, $daysinweek) && $day != null){
                    return response($day,422);
                }
            }
        }

        // Validate if each subject is in the subjectlist
        $subjects = $request -> subjects;
        if(!empty($subjects)){
            foreach($subjects as $subject){
                if(!in_array($subject, $subjectlist) && $subject != null){
                    return response($subject,422);
                }
            }
        }

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

        $course_log = new Logging;
        $course_log->level = 'info';
        $course_log->user_id = $course->user_id;
        $course_log->course_id = $course->id;
        $course_log->action = 'created a course';
        $course_log->save();

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
        if ($course->isMadeByStudent()){
            // find tutor name from course request
            $requesterId= CourseRequester::where('course_id','=',$courseId)
                                        ->where('status','=','Pending')
                                        ->get()->first();
            $requesterId = $requesterId->requester_id;
            $tutorName = User::find($requesterId)->name;
        }else{
            $tutorName = $course->user->name;
        }

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
        if(auth()->user()->id == $user_id){
            $course_id = $request->course_id;
            $registeredCourse = CourseStudent::where('user_id', $user_id)->where('course_id', '=', $course_id)->first();
            $registeredCourse->status = 'refunding';
            $registeredCourse->save();

            $refundInfo = $this->getRefundInfo($course_id);
            $isFullRefund = $refundInfo['isFullRefund'];
            $refundAmount = $refundInfo['refundAmount'];

            $refund = DB::table('payments')
                    ->where('user_id',$user_id)
                    ->where('status','successful')
                    ->join('carts','payments.id', '=', 'carts.payment_id')
                    ->select('payments.id','payments.pay_by_card','carts.course_id')
                    ->where('carts.course_id',$course_id)
                    ->first();

            RefundRequest::create([
                'user_id' => $user_id,
                'course_id' => $course_id,
                'payment_id' => $refund->id,
                'amount' => $refundAmount,
                'is_transferred' => !($refund->pay_by_card),
                'is_full_refund' => $isFullRefund
            ]);

            //  create cancel notification
            $username = User::where('id','=',$registeredCourse->user_id)->first()->name;
            $title = "Request to teach";
            $message = "{$username} have cancel the course";
            $receiver_id = Course::where('id','=',$registeredCourse->course_id)->first()->user_id;
            NotificationController::createNotification($receiver_id, $title, $message);


            $course_log = new Logging;
            $course_log->level = 'info';
            $course_log->user_id = $user_id;
            $course_log->course_id = $course_id;
            $course_log->action = 'canceled a course';
            $course_log->save();

            return response('OK', 200);
        }
        else {
            return response('Access denied', 401);
        }
    }

    public function postponeClass(Request $request){
        $class_id = $request->classId;
        $class = CourseClass::where('id', $class_id)->first();
        if ($class->status === 'Postponed'){
            return response("the class was already postponed", 401);
        }
        if ($class->date <= date("Y-m-d")) {
            return response("the class can not be postponed", 401);
        }

        $weekMap = [
            'Sunday' => 0,
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
        ];
        $course_id = $class->course_id;
        $course = Course::find($course_id);
        $weekDays = $course->days->pluck('name')->toArray();
        $date = new Carbon($class->date);
        $class->status = 'Postponed';
        $class->save();

        while(True){
            $min = $date->copy()->addDays(8);
            foreach($weekDays as $weekDay){
                $t = $date->copy()->next($weekMap[$weekDay]);
                if($t->lt($min)){
                    $min = $t;
                }
            }
            $existence = CourseClass::where('date', $min)->where('course_id', $course_id)->first();
            if ($existence === null) {
                $courseClass = new CourseClass;
                $courseClass->date = $min;
                $courseClass->time = $course->time;
                $courseClass->course_id = $course->id;
                $courseClass->hours = $course->hours;
                $courseClass->save();
                break;
            }
            $date = $min;
        }

        $title = 'Postponement';
        $message = 'The class on ' . date("j F Y", strtotime($class->date))
                    . ' has been postponed.';
                    // 'by ' . auth()->user()->name
                    // . ', the extended class will be in ' . date("j F Y", strtotime($date));
        NotificationController::multiNotify($course_id, $title, $message);

        $course_log = new Logging;
        $course_log->level = 'info';
        $course_log->user_id = $course->user_id;
        $course_log->course_id = $course->id;
        $course_log->action = 'postponed a class';
        $course_log->save();

        return response("completed", 200);
    }

    public function getCourseStatus($course_id){
        if(auth()->user()->role == 'student'){
            $status = CourseStudent::where('user_id', auth()->user()->id)->where('course_id', '=', $course_id)->first()->status;
            $refundInfo = $this->getRefundInfo($course_id);
            return response(['status' => $status, 'isFullRefund' => $refundInfo['isFullRefund'], 'refundAmount' => $refundInfo['refundAmount']], 200);
        }
        else{
            return response("No information for this role.", 200);
        }
    }

    public function getClassStatus($class_id){
        $class = CourseClass::where('id', $class_id)->first();
        // check whether user owns the course
        $course_id = $class->course_id;
        $course = CourseStudent::where('user_id', auth()->user()->id)->where('course_id', '=', $course_id)->first();
        if ($course) {
            return $class->status;
        } else {
            return response("access denied", 401);
        }
    }

    public function myCoursesIndex(){
        paymentGatewayController::checkRefund();
        $user = auth()->user();
        $courses;
        $students = [];
        $classDateList = [];
        $nextClasses = [];
        $isFinished = [];
        $classesLeft = [];
        if($user->isStudent()){
            $courses = $user->registeredCourses()->with(['days', 'subjects', 'location'])->orderBy('startDate', 'DESC')->paginate(10)->onEachSide(1);
            foreach($courses as $course){
                $course->user->name = $course->getTutorName();
            }
        }
        else if($user->isTutor()){
            $courses_own = Course::with(['days', 'subjects', 'location'])->where('user_id', auth()->user()->id);
            $requester_ids = CourseRequester::where('requester_id', $user->id)->where('status', 'Accepted')->pluck('course_id')->all();
            $course_request = Course::with(['days', 'subjects', 'location'])->whereIn('id', $requester_ids);
            $courses = $courses_own->unionAll($course_request)->orderBy('startDate', 'DESC')->paginate(10)->onEachSide(1);
        }
        foreach($courses as $course){
            array_push($students,$course->students);
            $now = Carbon::now()->addHours(7);
            $nextClass = $course->courseClasses->sortBy('date')->where('date', '>=', $now)->first();
            $lastClass = $course->courseClasses->sortBy('date')->last();
            array_push($classDateList, $course->courseClasses->sortBy('date')->pluck('date')->all());
            array_push($nextClasses, $nextClass?$nextClass->date:NULL);
            array_push($classesLeft, $course->courseClasses->where('date', '>=', $now)->count());
            array_push($isFinished, $lastClass?$lastClass->date < $now:NULL);
        }
        return view('my_courses', ['courses' => $courses, 'classDateList' => $classDateList, 'nextClasses' => $nextClasses, 'isFinished' => $isFinished, 'classesLeft' => $classesLeft, 'students' => $students]);
    }

    public function requestCourse(Request $request) {
        $receiver_id = Course::all()->where('id','=',$request->course_id)->pluck('user_id');
        $data = array('course_id'=>$request->course_id,"requester_id"=>auth()->user()->id,'receiver_id'=>$receiver_id[0],'status'=>'Init','updated_at'=>now());
        DB::table("course_requesters")->insert($data);
        // create Notification
        $username = User::where('id','=',auth()->user()->id)->first()->name;
        $message = "{$username} have request to teach your course";
        $title = "Request to teach";
        $receiver_id = Course::where('id','=',$request->course_id)->first()->user_id;
        NotificationController::createNotification($receiver_id, $title, $message);

        $course_log = new Logging;
        $course_log->level = 'info';
        $course_log->user_id = auth()->user()->id;
        $course_log->course_id = $request->course_id;
        $course_log->action = 'requested a course';
        $course_log->save();

        return response()->json(array('msg'=> "Done"), 200);
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

    public function getMyCourseRequest(Request $request){
        $userId = auth()->user()->id;
        $courses = Course::select('id','time','hours','price','startDate','noClasses')->where('user_id',$userId)->with('subjects:name','days:name')->get();
        $retCourses = [];
        foreach($courses as $course){
            if ($course != null){
                $subject = collect();
                $day = [];
                $status = CourseRequester::where('course_id','=',$course->id)->where('status','=','Accepted')->get()->isEmpty() ? 'Open':'Closed';
                foreach($course->subjects->map->only('name') as $sub){
                    $name = $sub['name'];
                    $subject->push($name);
                }
                foreach($course->days->map->only('name') as $d){
                    array_push($day,$d['name']);
                }
                $arr = [
                    'myCourseId' => $course->id,
                    'time' => $course->time,
                    'hours' => $course->hours,
                    'price' => $course->price,
                    'startDate' => $course->startDate,
                    'noClasses' => $course->noClasses,
                    'subjects' => $subject,
                    'date' => $day,
                    'status' => $status
                ];

                array_push($retCourses, $arr);

            }
        }
        return $retCourses;

    }

    public function getAdsCourses (Request $request) {
        $userId = auth()->user()->id;
        $myCourses = Course::select('id')->where('user_id','=',$userId)->get();
        $courses = [];
        foreach($myCourses as $courseId){
            $course = $this::getCourseInfo($courseId->id);
            // dd($course);
            if ($course != null){
                $course['isPromoted'] = Advertisement::where('course_id','=',$course['course_id'])->get()->isEmpty() ? false:true;
                $course['title'] = 'Course '.$courseId->id;
                $course['subjects'] = $course['subjects']->implode(',');
                $course['days'] = $course['days']->implode(',');
                $startDate = new Carbon($course['startDate']);
                $nowTime = Carbon::now();
                if ($startDate > $nowTime){
                    // choose only courses that are not start yet
                    array_push($courses,$course);
                }
            }
        }
        return $courses;
    }

    private function getRefundInfo($course_id){
        $course = Course::where('id', '=', $course_id)->first();
        $startDate = new Carbon($course->startDate);
        $now = Carbon::now()->addHours(7);
        $diff = $now->diffInDays($startDate, false);
        $isFullRefund = $diff > 3;

        $remainClasses = $course->courseClasses->sortBy('date')->where('date', '>=', $now)->count();
        $refundAmount = (int) ($remainClasses * $course->price / $course->noClasses);
        if(!$isFullRefund) $refundAmount = (int) ($refundAmount * 0.7);
        return ['refundAmount' => $refundAmount, 'isFullRefund' => $isFullRefund];
    }
}
