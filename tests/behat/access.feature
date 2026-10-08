@mod @mod_ailanguageteacher
Feature: Teachers build AI Language Teacher lessons and learners open them
  In order to teach spoken phrases in context
  As a teacher
  I need to build a lesson that my learners can open, while learners cannot reach the teacher pages

  Background:
    Given the following "courses" exist:
      | fullname      | shortname |
      | Language 101  | L101      |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | L101   | editingteacher |
      | student1 | L101   | student        |
    And the following "activities" exist:
      | activity          | course | idnumber | name             | intro              |
      | ailanguageteacher | L101   | lt1      | Greetings lesson | Learn to greet.    |
    And the following config values are set as admin:
      | name        | value                                         | plugin                |
      | unlockstate | {"status":"unlocked","checkedat":1790000000} | mod_ailanguageteacher |

  Scenario: A teacher opening an empty lesson is taken to the lesson builder
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "teacher1"
    Then I should see "Which language are you teaching?"

  Scenario: A learner opening an empty lesson is told it is not ready
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "student1"
    Then I should see "Your teacher is still preparing this lesson."
    And I should not see "Set up the lesson"

  Scenario: A learner sees the learning journey once a scene is ready
    Given the following "mod_ailanguageteacher > scenes" exist:
      | activity | title       | phrases                                        |
      | lt1      | At the door | Good morning=Buenos días\|Please come in=Pase |
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "student1"
    Then I should see "Study"
    And I should see "Practice"
    And I should see "Test"
    And I should see "See and hear each phrase in its real-life moment."
    And "Reports" "link" should not exist in current page administration

  Scenario: A teacher can open the scenes and the learners report
    Given the following "mod_ailanguageteacher > scenes" exist:
      | activity | title       | phrases                     |
      | lt1      | At the door | Good morning=Buenos días    |
    When I am on the "Greetings lesson" "mod_ailanguageteacher > Scenes" page logged in as "teacher1"
    Then I should see "At the door"
    And I should see "Edit phrases"
    And I should see "Step 8 of 9"
    And I am on the "Greetings lesson" "mod_ailanguageteacher > Report" page
    And I should see "Sam Student"
    And I should see "Mastered"

  Scenario: Without an LMS Labs connection the create step says why no scenes can be created
    Given I am on the "Greetings lesson" "mod_ailanguageteacher > Build" page logged in as "teacher1"
    Then I should see "Step 5 of 9"
    And I should see "Creating scenes needs this site's LMS Labs connection"
    And the "Next: Pictures" "button" should be disabled

  Scenario: The set-up path opens at the step it is up to and only moves forward when the step is done
    Given the following "mod_ailanguageteacher > scenes" exist:
      | activity | title       | phrases                  |
      | lt1      | At the door | Good morning=Buenos días |
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "teacher1"
    And I navigate to "Set up the lesson" in current page administration
    Then I should see "Step 9 of 9"
    And I should see "All 1 scenes are ready"
    And I click on "Back" "link" in the "div.lt-setupnav:not(.lt-finishactions)" "css_element"
    And I should see "Check each scene"

  Scenario: Nothing can be set up or studied until AI Language Teacher is activated on the site
    Given the following config values are set as admin:
      | name        | value               | plugin                |
      | unlockstate | {"status":"locked"} | mod_ailanguageteacher |
    And the following "mod_ailanguageteacher > scenes" exist:
      | activity | title       | phrases                  |
      | lt1      | At the door | Good morning=Buenos días |
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "student1"
    Then I should see "This activity is not available yet."
    And I am on the "Greetings lesson" "mod_ailanguageteacher > Builder" page logged in as "teacher1"
    And I should see "Ask your site administrator to activate it"
