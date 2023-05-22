angular.module('mm.addons.mod_zoom2', ['mm.core'])
.constant('mmaModZoom2Component', 'mmaModZoom2')
.config(["$stateProvider", function($stateProvider) {
  $stateProvider
    .state('site.mod_zoom2', {
      url: '/mod_zoom2',
      params: {
        module: null,
        courseid: null
      },
      views: {
        'site': {
          controller: 'mmaModZoom2IndexCtrl',
          templateUrl: '$ADDONPATH$/templates/index.html'
        }
      }
    });
}])
.config(["$mmCourseDelegateProvider", "$mmContentLinksDelegateProvider", function($mmCourseDelegateProvider, $mmContentLinksDelegateProvider) {
  $mmCourseDelegateProvider.registerContentHandler('mmaModZoom2', 'zoom2', '$mmaModZoom2Handlers.courseContentHandler');
  $mmContentLinksDelegateProvider.registerLinkHandler('mmaModZoom2', '$mmaModZoom2Handlers.linksHandler');
}]);

angular.module('mm.addons.mod_zoom2')
.controller('mmaModZoom2IndexCtrl', ["$scope", "$stateParams", "$mmaModZoom2", "$mmCourse", "$mmText", "$translate", "$q", "$mmUtil", function($scope, $stateParams, $mmaModZoom2, $mmCourse, $mmText, $translate, $q, $mmUtil) {
    var module = $stateParams.module || {},
        courseId = $stateParams.courseid;
    $scope.title = module.name;
    $scope.moduleUrl = module.url;
    $scope.componentId = module.id;
    $scope.canGetUrl = $mmaModZoom2.isGetUrlWSAvailable();
    function fetchContent() {
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
            $scope.startTime = new Date(res.start_time * 1000).toString(); 
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
      $mmaModZoom2.open(module.id);
    };
}]);

angular.module('mm.addons.mod_zoom2')
  .factory('$mmaModZoom2Handlers', ["$mmCourse", "$mmaModZoom2", "$state", "$mmContentLinksHelper", function($mmCourse, $mmaModZoom2, $state, $mmContentLinksHelper) {
    var self = {};
    self.courseContentHandler = function() {
      var self = {};
      self.isEnabled = function() {
        return true;
      };
      self.getController = function(module, courseId) {
        return function($scope) {
          $scope.title = module.name;
          $scope.icon = '$ADDONPATH$/icon.gif'
          $scope.class = 'mma-mod_zoom2-handler';
          $scope.action = function(e) {
            $state.go('site.mod_zoom2', {module: module, courseid: courseId});
            e.preventDefault();
            e.stopPropagation();
          };
          $scope.spinner = true;
          $mmCourse.loadModuleContents(module, courseId).then(function() {
            if (module.contents && module.contents[0] && module.contents[0].fileurl) {
              $scope.buttons = [{
                icon: 'ion-link',
                label: 'mm.core.openmeeting',
                action: function(e) {
                  if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                  }
                  $mmaModZoom2.logView(module.instance).then(function() {
                    $mmCourse.checkModuleCompletion(courseId, module.completionstatus);
                  });
                  $mmaModZoom2.open(module.contents[0].fileurl);
                }
              }];
            }
          }).finally(function() {
            $scope.spinner = false;
          });
        };
      };
      return self;
    };
    self.linksHandler = $mmContentLinksHelper.createModuleIndexLinkHandler('mmaModZoom2', 'zoom2', $mmaModZoom2);
    return self;
  }]);

angular.module('mm.addons.mod_zoom2')
.factory('$mmaModZoom2', ["$mmSite", "$mmUtil", "$q", "$mmContentLinksHelper", "$mmCourse", "$mmSitesManager", function($mmSite, $mmUtil, $q, $mmContentLinksHelper, $mmCourse, $mmSitesManager) {
    var self = {};
    var ZOOM2_GET_STATE = 'mod_zoom2_get_state';
    function getZoom2Data(courseId, moduleId) {
      return $mmSitesManager.getSite($mmSitesManager.getCurrentSite().id).then(function(site) {
        var params = {
          zoom2id: moduleId
        },
        preSets = {
          siteurl: $mmSite.getURL(),
          wstoken: $mmSite.getToken()
        };
        return site.read('mod_zoom2_get_state', params, preSets).then(function (response) {
          return response;
        });
      });
    }
    function getMeetingURL(zoom2Id) {
      return $mmSitesManager.getSite($mmSitesManager.getCurrentSite().id).then(function(site) {
        var params = {
          zoom2id: zoom2Id
        }, preSets = {
          siteurl: $mmSite.getURL(),
          wstoken: $mmSite.getToken()
        };
        return site.read('mod_zoom2_grade_item_update', params, preSets).then(function (response) {
          return response;
        });
      });
    }
    self.logView = function(id) {
        if (id) {
            var params = {
                urlid: id
            };
            return $mmSite.write(ZOOM2_GET_STATE, params);
        }
        return $q.reject();
    };
    self.isGetUrlWSAvailable = function() {
        return $mmSite.wsAvailable(ZOOM2_GET_STATE);
    };
    self.isPluginEnabled = function(siteId) {
      return $mmSitesManager.getSite(siteId).then(function(site) {
        return site.wsAvailable(ZOOM2_GET_STATE);
      });
    };
    self.getZoom2 = function(courseId, moduleId) {
      if (courseId === undefined || moduleId === undefined) {
        return $q.reject();
      }
      return getZoom2Data(courseId, moduleId);
    };
    self.open = function(moduleId) {
      var modal = $mmUtil.showModalLoading();
      getMeetingURL(moduleId).then(function (res) {
        return $mmSite.openInBrowserWithAutoLoginIfSameSite(res.joinurl);
      }).finally(function() {
        modal.dismiss();
      });
    };
    return self;
}]);
