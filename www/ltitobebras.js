function platformLoad(task,platform,metaData) {
	platform.openUrl = function(sTextId, success, error) {success();};
	platform.updateHeight = function(height,success,error) {
      $('#taskIframe').height(height);
      success();
   };
   var getHeightInterval = window.setInterval(function() {
      task.getHeight(function(height) {
         $('#taskIframe').height(height);
      });
   }, 1000);
   var syncStateInterval = window.setInterval(function() {
      task.getState(function(state) {
         if (state == lastState) {
            return;
         }
         $.post('api-entry.php', {platformName: platformName, action: 'saveState', sToken: token, sState: state}, function(res) {
            if (!res.success) {
               console.error('error in saving state');
               return;
            }
         }, 'json').fail(console.error);
      });
   }, 3000);

   var taskViews = {};
   var loadedViews = {'task': true, 'solution': true, 'hints': true, 'editor': true, 'grader': true, 'metadata': true, 'submission': true};
   var shownViews = {'task': true};
   var showViewsHandlerFactory = function (view) {
      return function() {
         var tmp = {};
         tmp[view] = true;
         task.showViews(tmp, function(){});
         $('.choose-view-button').removeClass('btn-info');
         if (buttonsPosition == 'top' || buttonsPosition == 'topbottom') {
            $('#choose-view-top-'+view).addClass('btn-info');
         }
         if (buttonsPosition == 'bottom' || buttonsPosition == 'topbottom') {
            $('#choose-view-bottom-'+view).addClass('btn-info');
         }
      };
   };
   var displayTabs = function() {
      $("#choose-view-top").html("");
      $("#choose-view-bottom").html("");
      for (var iView = 0; iView < viewOrder.length; iView++) {
         var viewName = viewOrder[iView];//taskViews
         if (!taskViews[viewName]) continue;
         if (!taskViews[viewName].requires && viewNames[viewName] && (viewName != 'solution' || bAccessSolution)) {
            if (buttonsPosition == 'top' || buttonsPosition == 'topbottom') {
               $("#choose-view-top").append($('<button id="choose-view-top-'+viewName+'" class="btn btn-default choose-view-button">' + viewNames[viewName] + '</button>').click(showViewsHandlerFactory(viewName)));
            }
            if (buttonsPosition == 'bottom' || buttonsPosition == 'topbottom') {
               $("#choose-view-bottom").append($('<button id="choose-view-bottom-'+viewName+'" class="btn btn-default choose-view-button">' + viewNames[viewName] + '</button>').click(showViewsHandlerFactory(viewName)));
            }
         }
      }
   };

	platform.getTaskParams = function(key, defaultValue, success, error) {
      var res = {'minScore': 0, 'maxScore': 100, 'noScore': 0, 'readOnly': false, 'randomSeed': 0, 'supportsTabs': true, 'options': {}, returnUrl: returnUrl};
      if (key) {
         if (key !== 'options' && key in res) {
            res = res[key];
         } else if (res.options && key in res.options) {
            res = res.options[key];
         } else {
            res = (typeof defaultValue !== 'undefined') ? defaultValue : null; 
         }
      }
      if (success) {
         success(res);
      } else {
         return res;
      }
   };
   platform.askHint = function(hintToken, success, error) {
      if (!usesTokens) {
         success();
         return;
      }
      $.post('api-entry.php', {taskPlatformName: taskPlatformName, action: 'askHint', hintToken: hintToken}, function(postRes){
         if (postRes.success && postRes.token) {
            token = postRes.token;
         	task.updateToken(token, function() {
         		success();
         	}, error);
         } else {
         	error('error in api-entry.php: '+postRes.error);
         }
      }, 'json').fail(error);
   };

   function showSolution() {
      bAccessSolution = true;
      displayTabs();
   }

   function gradeCurrentAnswer(success,error) {
      if (usesTokens) {
   		task.getAnswer(function (answer) {
            $.post('api-entry.php', {taskPlatformName: taskPlatformName, action: 'getAnswerToken', sToken: token, sAnswer: answer}, function(postRes){
               if (postRes.success && postRes.token) {
                  task.gradeAnswer(answer, postRes.token, function(score,message,scoreToken) {
                     $.post('api-entry.php', {taskPlatformName: taskPlatformName, action: 'graderReturn', score: score, message: message, scoreToken: scoreToken}, {responseType: 'json'}).success(function(postRes) {
                        if (postRes.success) {
                           success();
                           if (postRes.token) {
                              task.updateToken(postRes.token, function() {});
                              showSolution();
                           }
                        } else {
                           error('something went wrong with api-entry.php: '+postRes.error);
                        }
                     }, 'json').fail(error);
                  }, error);
               } else {
                  error('error in api-entry.php: '+postRes.error);
               }
            }, 'json').fail(error);
         }, error);
      } else {
         task.getAnswer(function (answer) {
            task.gradeAnswer(answer, null, function(score,message) {
               $.post('api-entry.php', {platformName: platformName, action: 'graderReturnNoToken', score: score, message: message, sToken: token, sAnswer: answer}, {responseType: 'json'}).success(function(postRes) {
                  if (postRes.success) {
                     success();
                     if (postRes.token) {
                        task.updateToken(postRes.token, function() {});
                        showSolution();
                     }
                  } else {
                     error('something went wrong with api-entry.php: '+postRes.error);
                  }
               }, 'json').fail(error);
            }, error);
         }, error);
      }
   }
	platform.validate = function(mode, success, error) {
	   if (mode == 'cancel') {
	      task.reloadAnswer('', success, error);
	      return;
       } else if(mode == 'nextImmediate') {
          window.close();
	   } else {
          gradeCurrentAnswer(success,error);
	   }
	};

   task.load(loadedViews, function() {
      task.getViews(function(views){
         taskViews = views;
         displayTabs();
      });
      task.showViews(shownViews, function() {
         $('.choose-view-button').removeClass('btn-info');
         $.each(shownViews, function(viewName) {
            if (buttonsPosition == 'top' || buttonsPosition == 'topbottom') {
               $('#choose-view-top-'+viewName).addClass('btn-info');
            }
            if (buttonsPosition == 'bottom' || buttonsPosition == 'topbottom') {
               $('#choose-view-bottom-'+viewName).addClass('btn-info');
            }
         });
      });
      if (lastState) {
         task.reloadState(lastState, function() {});
      }
      task.reloadAnswer(lastAnswer, function() {});
   });
}

function init() {
	TaskProxyManager.getTaskProxy('taskIframe', function(task) {
		var platform = new Platform(task);
		TaskProxyManager.setPlatform(task, platform);
		task.getMetaData(function(metaData) {
         platformLoad(task, platform, metaData);
     	});
  	}, true);
}

$(document).ready(function() {
	init();
});
