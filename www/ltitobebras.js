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
   var frenchName = {
      'task': 'Exercice',
      'solution': 'Solution',
      'editor': 'Résoudre',
      'hints': 'Conseils'
   };
   var loadedViews = {'task': true, 'solution': true, 'hints': true, 'editor': true, 'grader': true, 'metadata': true, 'submission': true};
   var shownViews = {'task': true};
   var showViewsHandlerFactory = function (view) {
      return function() {
         var tmp = {};
         tmp[view] = true;
         task.showViews(tmp, function(){});
         $('.choose-view-button').removeClass('btn-info');
         $('#choose-view-'+view).addClass('btn-info');
      };
   };
   var displayTabs = function() {
      $("#choose-view").html("");
      for (var viewName in taskViews)
      {
         if (!taskViews[viewName].requires && frenchName[viewName] && (viewName != 'solution' || bAccessSolution)) {
            $("#choose-view").append($('<button id="choose-view-'+viewName+'" class="btn btn-default choose-view-button">' + frenchName[viewName] + '</button>').click(showViewsHandlerFactory(viewName)));
         }
      }
   };

	platform.getTaskParams = function(key, defaultValue, success, error) {
      var res = {'minScore': 0, 'maxScore': 100, 'noScore': 0, 'readOnly': false, 'randomSeed': 0, 'options': {}, returnUrl: returnUrl};
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
         	task.updateToken(token, function() {
               token = postRes.token;
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
            $('#choose-view-'+viewName).addClass('btn-info');
         });
      });
      task.reloadAnswer(lastAnswer, function() {});
      if (lastState) {
         task.reloadState(lastState, function() {});
      }
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