# IoT | Telegram Fingerprint Door Lock and Surveillance Camera w/ Night Vision
#
# Raspberry Pi 3 Model B+ or 4
#
# By Kutluhan Aktar
#
# Via Telegram, get apprised of every attempt to lock or unlock the door w/ surveillance footage
# by night vision camera or USB Webcam.
#
# For more information:
# https://www.theamplituhedron.com/projects/IoT-Telegram-Fingerprint-Door-Lock-and-Surveillance-Camera-w-Night-Vision/

from picamera import PiCamera
import json
from time import sleep
import datetime
from subprocess import call 
import requests
import RPi.GPIO as GPIO
import adafruit_fingerprint
import serial

# Set up BCM GPIO numbering
GPIO.setmode(GPIO.BCM)
GPIO.setwarnings(False)
# Set up Relay pins:
lock = 4
GPIO.setup(lock, GPIO.OUT)
GPIO.output(lock, GPIO.HIGH)

# Create the Surveillance System class with the required settings:
class Surveillance_System:
    def __init__(self, server, file_location):
        # Define the fingerprint sensor settings (USB/serial converter).
        uart = serial.Serial("/dev/ttyUSB0", baudrate=57600, timeout=1)
        self.finger = adafruit_fingerprint.Adafruit_Fingerprint(uart)
        # Define the server (Telegram Bot) and the file location (captured).
        self.server = server
        self.file_location = file_location
        # Define the Night Vision Camera Settings.
        self.night_cam = PiCamera()
        self.night_cam.resolution = (640, 480)
        self.night_cam.framerate = 15
        # Define the default camera type setting (USB Webcam).
        self.camera_type = "USB"
        self.surveillance_request = "default"
    # Get a fingerprint image, template it, and see if it matches.
    def detect_fingerprint(self):
        if self.finger.get_image() != adafruit_fingerprint.OK:
            sleep(1)
            return "Waiting"
        print("Templating...")
        if self.finger.image_2_tz(1) != adafruit_fingerprint.OK:
            return "Not Reading"
        print("Searching...")
        if self.finger.finger_search() != adafruit_fingerprint.OK:
            return "Not Found"
        print("Detected #", self.finger.finger_id, "with confidence", self.finger.confidence)
        return "Found"
    # Get updates from the Telegram Bot via the PHP web application (telegram_surveillance_bot).
    def get_updates_from_web_app(self):
        data = requests.get(self.server+"?data=ok");
        # If incoming data:
        if(data.text == "Waiting new commands..."):
            pass
        else:
            self.commands = json.loads(data.text)
            self.camera_type = self.commands["camera"]
            self.surveillance_request = self.commands["surveillance"]
    # According to the selected camera type (Night Vision or USB Webcam), capture the recent attempt to lock or unlock the door.
    def capture_last_attempt(self, _date, file_name, camera_type):
        file_name = self.file_location + file_name
        # Night Vision Camera:
        if(camera_type == "night"):
            # Add date as timestamp on the generated files.
            self.night_cam.annotate_text = _date
            # Capture an image as the thumbnail.
            sleep(2)
            self.night_cam.capture(file_name+".jpg")
            print("\r\nRasp_Pi => Image Captured!\r\n")
            # Record a 5 seconds video.
            self.night_cam.start_recording(file_name+".h264")
            sleep(10)
            self.night_cam.stop_recording()
            print("Rasp_Pi => Video Recorded! \r\n")
            # Convert the H264 format to the MP4 format.
            command = "MP4Box -add " + file_name + ".h264" + " " + file_name + ".mp4"
            call([command], shell=True)
            print("\r\nRasp_Pi => Video Converted! \r\n")
        # USB Webcam:
        elif (camera_type == "USB"):
            # Capture an image with Fswebcam module.
            width = "640"
            height = "480"
            command_capture = "fswebcam -D 2 -S 20 -r " + width + "x" + height + " " + file_name + ".jpg"
            call([command_capture], shell=True)
            print("\r\nRasp_Pi => Image Captured!\r\n")
            # Record a 5 seconds video with FFmpeg.
            command_record = "ffmpeg -f video4linux2 -r 20 -t 5 -s " + width + "x" + height + " -i /dev/video0 " + file_name + ".avi"
            call([command_record], shell=True)
            print("Rasp_Pi => Video Recorded! \r\n")
            # Convert the AVI format to the MP4 format.
            command = "MP4Box -add " + file_name + ".avi" + " " + file_name + ".mp4"
            call([command], shell=True)
            print("\r\nRasp_Pi => Video Converted! \r\n")
            sleep(3)
    # Send the recently captured files to the server (web app).
    def send_last_attempt(self, _file_name):
        file_name = self.file_location + _file_name
        # Files:
        files = {'rasp_video': open(file_name+".mp4", 'rb'), 'rasp_capture': open(file_name+".jpg", 'rb')}
        # Last Entry:
        data = {'access': _file_name}
        # Make an HTTP Post Request to the server to send the files.
        request = requests.post(self.server, files=files, data=data)
        # Print the response from the server.
        print("Rasp_Pi => Files Transferred!\r\n")
        print(request.text+"\r\n")

# Define a new class object named 'surveillance':
surveillance = Surveillance_System("https://www.theamplituhedron.com/telegram_surveillance_bot/", "/home/pi/Telegram_Surveillance_System_w_Fingerprint/captured/") # Change with your settings.

while True:
    # Get updates from the Telegram Bot via the PHP web app.
    surveillance.get_updates_from_web_app()
    sleep(5)
    # If surveillance footage requested without triggering the fingerprint sensor:
    if(surveillance.surveillance_request == "footage"):
        surveillance.surveillance_request = "default"
        print("Bot => Footage Requested!\r\n")
        date = datetime.datetime.now().strftime("%m-%d-%y_%H-%M-%S")
        surveillance.capture_last_attempt(date, "requested_"+date, surveillance.camera_type)
        surveillance.send_last_attempt("requested_"+date)
    # Detect whether the fingerprint is found or not.
    fingerprint_sta = surveillance.detect_fingerprint()
    if(fingerprint_sta == "Waiting"):
        print("Waiting for image...")
    elif(fingerprint_sta == "Found"):
        # Lock or unlock:
        if(GPIO.input(lock)):
            GPIO.output(lock, GPIO.LOW)
        else:
            GPIO.output(lock, GPIO.HIGH)
        print("Fingerprint => Detected!")
        date = datetime.datetime.now().strftime("%m-%d-%y_%H-%M-%S")
        surveillance.capture_last_attempt(date, "access_"+date, surveillance.camera_type)
        surveillance.send_last_attempt("access_"+date)
    elif(fingerprint_sta == "Not Found"):
        print("Fingerprint => Not Found!")
        date = datetime.datetime.now().strftime("%m-%d-%y_%H-%M-%S")
        surveillance.capture_last_attempt(date, "failed_"+date, surveillance.camera_type)
        surveillance.send_last_attempt("failed_"+date)