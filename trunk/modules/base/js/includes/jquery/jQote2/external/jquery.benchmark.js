// based on methodology developed by PPK:
// http://www.quirksmode.org/blog/archives/2009/08/when_to_read_ou.html
(function($){
$.benchmark = function(n, contestant, test){
  var startTime = new Date().getTime();
  
  while (n--)
    contestant.benchmarks[test].call(contestant.templates);

  setTimeout(function () {
    var endTime = new Date().getTime();
    var result = (endTime-startTime)/1000;
    contestant.results.push(result);
  },10);
};
})(jQuery);
