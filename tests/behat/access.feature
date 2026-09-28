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

  Scenario: A teacher opening an empty lesson is taken to the lesson builder
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "teacher1"
    Then I should see "Which language are you teaching?"

  Scenario: A learner opening an empty lesson is told it is not ready
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "student1"
    Then I should see "Your teacher is still preparing this lesson."
    And I should not see "Build lesson"

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
    And I am on the "Greetings lesson" "mod_ailanguageteacher > Report" page
    And I should see "Sam Student"
    And I should see "Mastered"

  Scenario: The build step offers the copy-and-paste route while AI drafting is unavailable
    Given I am on the "Greetings lesson" "mod_ailanguageteacher > Build" page logged in as "teacher1"
    Then I should see "Copy prompt"
    And I should see "Paste the AI's reply"
    And I should see "AI drafting is not available on this site yet."
