var casper = require('casper').create({
    timeout: 20000,
    onTimeout: function() {
        this.echo('request time out !');
        this.exit();
    },
    viewportSize: {
        width: 1900,
        height: 800
    },
    verbose: true,
    logLevel: 'error',
    pageSettings: {
        loadImages:  true,
        loadPlugins: true,
        webSecurityEnabled: true
    }
});

casper.userAgent("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36");

phantom.outputEncoding="gbk";

var url = 'http://www.taoyizhu.com/';

casper.start(url,function(){});

var fs = require('fs');
var tb_name = fs.read('TB_USER_NAME.txt');

if(!tb_name){
    casper.echo('tb name not empty !');
    casper.exit();
}

casper.wait(7000, function(){
    this.sendKeys('#txt_name', tb_name, {reset: true});
    casper.wait(1500, function(){
        this.click('#search_btn');
    });
    casper.wait(1500, function(){
        // 记录信息获取截图
        this.capture('data/tb_user_audit/tb_user_audit.png');

        // 淘宝号是否存在，默认存在
        this.echo(casper.evaluate(function() {
            var info = document.querySelector("div#UserInfo p");
            return info ? info.innerText : '';
        }));

        // 淘宝号注册时间
        this.echo(casper.evaluate(function(){
            var info = document.querySelector("span#rate_userTime");
            return info ? info.innerText : '';
        }));

        // 淘宝号支付宝认证，默认否
        this.echo(casper.evaluate(function(){
            var info = document.querySelector("span#rate_userIdent");
            return info ? info.innerText : '';
        }));

        // 淘宝号所在地区
        this.echo(casper.evaluate(function(){
            var info = document.querySelector("span#rate_userArea");
            return info ? info.innerText : '';
        }));

        // 淘宝号买家信誉值
        this.echo(casper.evaluate(function(){
            var info = document.querySelector("span#spanUserBuyerCount");
            return info ? info.innerText : '';
        }));

        // 淘宝号是否安全
        this.echo(casper.evaluate(function(){
            var info = document.querySelector("div.nick_lever img");
            return info ? info.getAttribute('alt') : '';
        }));
    });
});

casper.run();