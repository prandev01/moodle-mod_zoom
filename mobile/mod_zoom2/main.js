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

angular.module('mm.addons.mod_zoom2', ['mm.core'])

.constant('mmaModZoom2Component', 'mmaModZoom2')

.config(function($stateProvider) {

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
          templateUrl: 'addons/mod/zoom2/templates/index.html'
        }
      }
    });

})

.config(function($mmCourseDelegateProvider, $mmContentLinksDelegateProvider) {
  $mmCourseDelegateProvider.registerContentHandler('mmaModZoom2', 'zoom2', '$mmaModZoom2Handlers.courseContentHandler');

  // Register content links handler.
  $mmContentLinksDelegateProvider.registerLinkHandler('mmaModZoom2', '$mmaModZoom2Handlers.linksHandler');

});

