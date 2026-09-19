' 隐藏执行调度任务（无命令行窗口）
Set ws = CreateObject("Wscript.Shell")
ws.Run """C:\Users\Administrator\AppData\Roaming\Local\lightning-services\php-8.2.27+1\bin\win64\php.exe"" ""e:\Dxcx\think"" schedule:run", 0, False
