function platformLoad(task,platform,metaData) {
	console.error(platform);
	platform.openUrl = function(sTextId, success, error) {success();};
	platform.updateHeight = function(height,success,error) {success();};
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
      $.post('api-entry.php', JSON.stringify({taskPlatformName: taskPlatformName, action: 'askHint', hintToken: hintToken}), function(postRes){
         if (postRes.success && postRes.token) {
         	task.updateToken(token, function() {
         		success();
         	}, error);
         } else {
         	error('error in api-entry.php: '+postRes.error);
         }
      }, 'json').fail(error);
   };
   function gradeCurrentAnswer(success,error) {
		task.getAnswer(function (answer) {
         task.gradeAnswer(answer, postRes.sAnswerToken, function(score,message,scoreToken) {
         	$.post('api-entry.php', {taskPlatformName: taskPlatformName, action: 'graderReturn', score: score, message: message, scoreToken: scoreToken}, {responseType: 'json'}).success(function(postRes) {
         		if (postRes.success) {
         			success();	
         		} else {
         			error('something went wrong with api-entry.php: '+postRes.error);
         		}
            }, 'json').fail(error);

         }, error);
      }, error);
   }
	platform.validate = function(mode, success, error) {
	   if (mode == 'cancel') {
	      task.reloadAnswer('', success, error);
	      return;
	   } else {
      	gradeCurrentAnswer(success,error);
	   }
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
   var frenchName = {
      'task': 'Exercice',
      'submission': 'Soumission',
      'solution': 'Solution',
      'editor': 'Résoudre',
      'hints': 'Conseils'
   };
   task.load(loadedViews, function() {
      task.getViews(function(views){
         $("#choose-view").html("");
         for (var viewName in views)
         {
            if (!views[viewName].requires) {
               $("#choose-view").append($('<button id="choose-view-'+viewName+'" class="btn btn-default choose-view-button">' + frenchName[viewName] + '</button>').click(showViewsHandlerFactory(viewName)));
            }
         }
      });
      task.showViews(shownViews, function() {
         $('.choose-view-button').removeClass('btn-info');
         $.each(shownViews, function(viewName) {
            $('#choose-view-'+viewName).addClass('btn-info');
         });
      });
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