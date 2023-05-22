// (C) Copyright 2015 Martin Dougiamas
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.

angular.module('mm.addons.mod_zoom2')

/**
 * URL index controller.
 *
 * @module mm.addons.mod_zoom2
 * @ngdoc controller
 * @name mmaModZoom2IndexCtrl
 */
.controller('mmaModZoom2IndexCtrl', function($scope, $stateParams, $mmaModZoom2, $mmCourse, $mmText, $translate, $q, $mmUtil) {
    var module = $stateParams.module || {},
        courseId = $stateParams.courseid;

    $scope.title = module.name;
    $scope.moduleUrl = module.url;
    $scope.componentId = module.id;
    $scope.canGetUrl = $mmaModZoom2.isGetUrlWSAvailable();

    function fetchContent() {
        // Fetch the module data.
        var promise = $mmaModZoom2.getZoom2(courseId, module.id);
        return promise.then(function(res) {
            $scope.title = "Zoom2 Meeting";
            $scope.description = res.intro || res.description;
            $scope.status = res.status;
            $scope.joinMeetingBeforeHost = res.joinbeforehost;
            $scope.startWhenHostJoins = res.startvideohost;
            $scope.startWhenParticipantJoins = res.startvideopart;
            $scope.audioOptions = res.audioopt;
            $scope.passwordProtected = res.haspassword;
            $scope.startTime = new Date(res.start_time * 1000).toString(); // Adjust from PHP timestamp format
            $scope.hasStartTime = res.start_time !== 0;
            $scope.available = res.available;
        }).catch(function(error) {
            $mmUtil.showErrorModalDefault(error, 'mm.course.errorgetmodule', true);
            return $q.reject();
        }).finally(function() {
            $scope.loaded = true;
            $scope.refreshIcon = 'ion-refresh';
        });
    }

    fetchContent();

    $scope.go = function() {
      $mmaModZoom2.logView(module.instance).then(function () {
        $mmCourse.checkModuleCompletion(courseId, module.completionstatus);
      });
      $mmaModZoom.open(module.id);
    };

});
